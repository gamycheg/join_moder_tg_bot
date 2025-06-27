<?php
class JoinRequestHandler {
    private $db;
    private $botToken;
    private $channelId;
    private $questions = [
        1 => "Как вас зовут? (ФИО)",
        2 => "Сколько вам лет?",
        3 => "Род деятельности, чем занимаетесь?",
        4 => "С какой целью решили вступить в объединение?",
        5 => "Ваше отношение к религии (христианин, родновер, агностик и т.п.)?",
        6 => "Откуда вы узнали о нашем канале?",
        7 => "Ваш номер телефона для связи (не обязательно)"
    ];
    
    public function __construct($db, $botToken, $channelId) {
        $this->db = $db;
        $this->botToken = $botToken;
        $this->channelId = $channelId;
        $this->initDB();
    }
    
    private function initDB() {
        $this->db->exec("CREATE TABLE IF NOT EXISTS requests (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id BIGINT NOT NULL,
            username VARCHAR(255),
            first_name VARCHAR(255),
            last_name VARCHAR(255),
            chat_id BIGINT NOT NULL,
            status ENUM('pending', 'approved', 'rejected') DEFAULT 'pending',
            request_date TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            captcha_answer VARCHAR(10),
            captcha_solved TINYINT(1) DEFAULT 0,
            current_question INT DEFAULT 0,
            completed_questions TINYINT(1) DEFAULT 0,
            INDEX idx_user_id (user_id),
            INDEX idx_status (status)
        )");
        
        $this->db->exec("CREATE TABLE IF NOT EXISTS interactions (
            id INT AUTO_INCREMENT PRIMARY KEY,
            request_id INT NOT NULL,
            message_text TEXT,
            is_bot_message TINYINT(1),
            message_date TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (request_id) REFERENCES requests(id) ON DELETE CASCADE,
            INDEX idx_request_id (request_id)
        )");
        
        $this->db->exec("CREATE TABLE IF NOT EXISTS answers (
            id INT AUTO_INCREMENT PRIMARY KEY,
            request_id INT NOT NULL,
            question_id INT NOT NULL,
            question_text TEXT NOT NULL,
            answer_text TEXT,
            answer_date TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (request_id) REFERENCES requests(id) ON DELETE CASCADE,
            INDEX idx_request_id (request_id),
            INDEX idx_question (question_id)
        )");
        
        $this->db->exec("CREATE TABLE IF NOT EXISTS admin_messages (
            id INT AUTO_INCREMENT PRIMARY KEY,
            request_id INT NOT NULL,
            admin_id BIGINT NOT NULL,
            message_id INT NOT NULL,
            FOREIGN KEY (request_id) REFERENCES requests(id) ON DELETE CASCADE,
            UNIQUE KEY unique_admin_message (request_id, admin_id)
        )");
    }
    
    public function handle($update) {
        try {
            if (isset($update['chat_join_request'])) {
                $this->handleJoinRequest($update['chat_join_request']);
            } elseif (isset($update['message']['text'])) {
                $this->handleUserResponse($update['message']);
            } elseif (isset($update['callback_query'])) {
                $this->handleAdminAction($update['callback_query']);
            }
        } catch (PDOException $e) {
            error_log("Database error in request handler: " . $e->getMessage());
            $this->notifyAdmins("⚠️ Ошибка базы данных: " . substr($e->getMessage(), 0, 1000));
        } catch (Exception $e) {
            error_log("Error in request handler: " . $e->getMessage());
            $this->notifyAdmins("⚠️ Ошибка обработки: " . substr($e->getMessage(), 0, 1000));
        }
    }
    
    private function handleJoinRequest($request) {
        $userId = $request['from']['id'];
        $chatId = $request['chat']['id'];
        
        if ($chatId == $this->channelId || $chatId == str_replace('@', '', $this->channelId)) {
            $username = (isset($request['from']['username']) and $request['from']['username']!='') ?$request['from']['username']: null;
            $firstName = $request['from']['first_name'] ?$request['from']['first_name']: 'Пользователь';
            $lastName = (isset($request['from']['last_name']) and  $request['from']['last_name']!='')?$request['from']['last_name']: '';
            $captcha = $this->generateCaptcha();
            
            // Сохраняем заявку в базу
            $stmt = $this->db->prepare('INSERT INTO requests 
                                      (user_id, username, first_name, last_name, chat_id, captcha_answer) 
                                      VALUES (:user_id, :username, :first_name, :last_name, :chat_id, :captcha)');
            $stmt->execute([
                ':user_id' => $userId,
                ':username' => $username,
                ':first_name' => $firstName,
                ':last_name' => $lastName,
                ':chat_id' => $chatId,
                ':captcha' => $captcha
            ]);
            $requestId = $this->db->lastInsertId();
            
            // Отправляем сообщение с капчей пользователю
            $message = "Привет, {$firstName}!\n\n";
            $message .= "Для вступления в канал решите простую капчу:\n";
            $message .= "Напишите число: <b>{$captcha}</b>\n\n";
            $message .= "Это необходимо для подтверждения, что вы не бот.";
            
            $this->sendMessage($userId, $message);
            
            // Уведомляем админов о новой заявке
            $this->notifyAdminsAboutNewRequest($requestId, $firstName, $lastName, $username, $userId, $captcha);
        }
    }
    
    private function handleUserResponse($message) {
        $userId = $message['from']['id'];
        $text = trim($message['text']);
        
        // Ищем активную заявку пользователя
        $stmt = $this->db->prepare('SELECT * FROM requests 
                                   WHERE user_id = ? AND status = "pending" 
                                   ORDER BY request_date DESC LIMIT 1');
        $stmt->execute([$userId]);
        $request = $stmt->fetch();
        
        if ($request) {
            // Сохраняем сообщение пользователя в историю
            $this->db->prepare('INSERT INTO interactions (request_id, message_text, is_bot_message) 
                              VALUES (?, ?, 0)')
                   ->execute([$request['id'], $text]);
            
            if (!$request['captcha_solved']) {
                $this->processCaptchaResponse($request, $text);
            } elseif ($request['current_question'] > 0 && !$request['completed_questions']) {
                $this->processQuestionResponse($request, $text);
            }
        }
    }
    
    private function processCaptchaResponse($request, $userAnswer) {
        if ($userAnswer === $request['captcha_answer']) {
            // Капча решена верно
            $this->db->prepare('UPDATE requests SET captcha_solved = 1, current_question = 1 WHERE id = ?')
                   ->execute([$request['id']]);
            
            // Получаем первый вопрос
            $firstQuestion = $this->questions[1];
            
            $reply = "✅ Капча решена верно!\n\n";
            $reply .= "Теперь ответьте на несколько вопросов:\n\n";
            $reply .= "<b>Вопрос 1/".count($this->questions).":</b>\n";
            $reply .= $firstQuestion;
            
            // Сохраняем вопрос в базу
            $this->db->prepare('INSERT INTO answers (request_id, question_id, question_text) 
                              VALUES (?, ?, ?)')
                   ->execute([$request['id'], 1, $firstQuestion]);
            
            // Отправляем вопрос пользователю
            $this->sendMessage($request['user_id'], $reply);
            
            // Уведомляем админов
            $adminText = "✅ Пользователь @" . ($request['username'] ?$request['username']: $request['first_name']) . " правильно решил капчу";
            $this->notifyAdmins($adminText, $request['id']);
        } else {
            // Неверная капча
            $reply = "❌ Неверный ответ. Пожалуйста, попробуйте еще раз.";
            $this->sendMessage($request['user_id'], $reply);
            
            $adminText = "❌ Пользователь @" . ($request['username'] ?$request['username']: $request['first_name']) . " ошибся в капче (ответил: {$userAnswer})";
            $this->notifyAdmins($adminText, $request['id']);
        }
    }
    
    private function processQuestionResponse($request, $userAnswer) {
        $currentQ = $request['current_question'];
        
        // Сохраняем ответ на текущий вопрос
        $this->db->prepare('UPDATE answers SET answer_text = ? 
                          WHERE request_id = ? AND question_id = ?')
               ->execute([$userAnswer, $request['id'], $currentQ]);
        
        // Отправляем ответ админам
        //$questionText = $this->questions[$currentQ];
        //$adminMessage = "📝 Ответ на вопрос {$currentQ}/".count($this->questions).":\n";
        //$adminMessage .= "<b>{$questionText}</b>\n";
        //$adminMessage .= $userAnswer;
        //$this->notifyAdmins($adminMessage, $request['id']);
        
        // Если есть следующий вопрос
        if (isset($this->questions[$currentQ + 1])) {
            $nextQ = $currentQ + 1;
            $nextQuestion = $this->questions[$nextQ];
            
            // Обновляем текущий вопрос
            $this->db->prepare('UPDATE requests SET current_question = ? WHERE id = ?')
                   ->execute([$nextQ, $request['id']]);
            
            // Сохраняем следующий вопрос в базу
            $this->db->prepare('INSERT INTO answers (request_id, question_id, question_text) 
                              VALUES (?, ?, ?)')
                   ->execute([$request['id'], $nextQ, $nextQuestion]);
            
            $reply = "✅ Ответ сохранен!\n\n";
            $reply .= "<b>Вопрос {$nextQ}/".count($this->questions).":</b>\n";
            $reply .= $nextQuestion;
            
            $this->sendMessage($request['user_id'], $reply);
        } else {
            $this->completeQuestionnaire($request);
        }
    }
    
    private function completeQuestionnaire($request) {
        $this->db->prepare('UPDATE requests SET completed_questions = 1 WHERE id = ?')
               ->execute([$request['id']]);
        
        $reply = "🎉 Спасибо за ответы! Ваша заявка будет рассмотрена в ближайшее время.";
        $this->sendMessage($request['user_id'], $reply);
        
        // Отправляем итоговые ответы админам
        $this->sendFinalAnswersToAdmins($request['id']);
    }
    
    private function handleAdminAction($callback) {
        $data = $callback['data'];
        $adminId = $callback['from']['id'];
        $callbackId = $callback['id'];
    
        if (!in_array($adminId, ADMINS)) {
            $this->answerCallback($callbackId, '❌ У вас нет прав!');
            return;
        }
    
        if (str_starts_with($data, 'approve_') || str_starts_with($data, 'reject_')) {
            $requestId = (int) substr($data, strpos($data, '_') + 1);
            $action = str_starts_with($data, 'approve_') ? 'approved' : 'rejected';
        
            try {
                // Получаем данные заявки
                $stmt = $this->db->prepare('SELECT * FROM requests WHERE id = ?');
                $stmt->execute([$requestId]);
                $request = $stmt->fetch();
            
                if (!$request) {
                    $this->answerCallback($callbackId, '❌ Заявка не найдена');
                    return;
                }
            
                // Выполняем действие через Telegram API
                if ($action === 'approved') {
                    $apiResponse = $this->approveChatJoinRequest(
                        $this->channelId,
                        $request['user_id']
                    );
                    
                    $userMessage = "🎉 Ваша заявка одобрена! Добро пожаловать в канал!";
                } else {
                    $apiResponse = $this->declineChatJoinRequest(
                        $this->channelId,
                        $request['user_id']
                    );
                    
                    $userMessage = "😞 Ваша заявка отклонена администратором.";
                }
            
                // Обновляем статус в базе
                $this->db->prepare('UPDATE requests SET status = ? WHERE id = ?')
                       ->execute([$action, $requestId]);
            
                // Отправляем уведомление пользователю
                $this->sendMessage($request['user_id'], $userMessage);
            
                // Обновляем сообщения админов
                $statusText = $action === 'approved' ? 'ОДОБРЕНО' : 'ОТКЛОНЕНО';
                $adminText = $callback['message']['text'] . "\n\n";
                $adminText .= "✅ Статус: {$statusText}\n";
                $adminText .= "👤 Админ: @" . $callback['from']['username'] . "\n";
                $adminText .= "🕒 Время: " . date('Y-m-d H:i:s');
            
                $this->updateAdminMessages($requestId, $adminText);
                $this->answerCallback($callbackId, "Заявка {$statusText}");
            
            } catch (Exception $e) {
                error_log("Admin action error: " . $e->getMessage());
                $this->answerCallback($callbackId, '❌ Ошибка: ' . $e->getMessage());
            }
        }
    }
    
    private function approveChatJoinRequest($chatId, $userId) {
        return $this->request('approveChatJoinRequest', [
            'chat_id' => $chatId,
            'user_id' => $userId
        ]);
    }
    
    private function declineChatJoinRequest($chatId, $userId) {
        return $this->request('declineChatJoinRequest', [
            'chat_id' => $chatId,
            'user_id' => $userId
        ]);
    }
    
    private function notifyAdminsAboutNewRequest($requestId, $firstName, $lastName, $username, $userId, $captcha) {
        $message = "🆕 Новая заявка на вступление:\n";
        $message .= "👤 Пользователь: {$firstName} {$lastName}\n";
        $message .= "🔗 Username: @" . ($username ?$username: 'не указан') . "\n";
        $message .= "🆔 ID: {$userId}\n";
        $message .= "🔢 Капча: {$captcha}\n";
        $message .= "📝 Заявка #{$requestId}";
        
        $replyMarkup = [
            'inline_keyboard' => [
                [
                    ['text' => '✅ Одобрить', 'callback_data' => "approve_{$requestId}"],
                    ['text' => '❌ Отклонить', 'callback_data' => "reject_{$requestId}"]
                ]
            ]
        ];
        
        foreach (ADMINS as $adminId) {
            $response = $this->sendMessage($adminId, $message, $replyMarkup);
            $responseData = json_decode($response, true);
            
            if ($responseData && $responseData['ok']) {
                $this->db->prepare('INSERT INTO admin_messages (request_id, admin_id, message_id) 
                                  VALUES (?, ?, ?)')
                       ->execute([$requestId, $adminId, $responseData['result']['message_id']]);
            }
        }
    }
    
    private function updateAdminMessages($requestId, $newText) {
        $stmt = $this->db->prepare('SELECT admin_id, message_id FROM admin_messages WHERE request_id = ?');
        $stmt->execute([$requestId]);
        $messages = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
        
        foreach ($messages as $adminId => $messageId) {
            $this->editMessage($adminId, $messageId, $newText, [
                'inline_keyboard' => [] // Убираем кнопки
            ]);
        }
    }
    
    private function sendFinalAnswersToAdmins($requestId) {
        $stmt = $this->db->prepare('
            SELECT a.question_id, a.question_text, a.answer_text, r.user_id, r.username, r.first_name 
            FROM answers a
            JOIN requests r ON a.request_id = r.id
            WHERE a.request_id = ?
            ORDER BY a.question_id
        ');
        $stmt->execute([$requestId]);
        $answers = $stmt->fetchAll();
        
        if (empty($answers)) return;
        
        $userInfo = $answers[0];
        $message = "📋 Итоговые ответы по заявке #{$requestId}\n";
        $message .= "👤 Пользователь: @{$userInfo['username']} ({$userInfo['first_name']})\n";
        $message .= "🆔 ID: {$userInfo['user_id']}\n\n";
        
        foreach ($answers as $answer) {
            $message .= "<b>{$answer['question_text']}</b>\n";
            $message .= $answer['answer_text'] . "\n\n";
        }
        
        foreach (ADMINS as $adminId) {
            $this->sendMessage($adminId, $message, [
                'parse_mode' => 'HTML',
                'disable_web_page_preview' => true
            ]);
        }
    }
    
    private function notifyAdmins($text, $requestId = null) {
        foreach (ADMINS as $adminId) {
            $message = $text;
            if ($requestId) {
                $message = "📌 Заявка #{$requestId}\n" . $message;
            }
            $this->sendMessage($adminId, $message);
        }
    }
    
    private function generateCaptcha() {
        return rand(1000, 9999);
    }
    
    private function sendMessage($chatId, $text, $replyMarkup = null) {
        $data = [
            'chat_id' => $chatId,
            'text' => $text,
            'parse_mode' => 'HTML'
        ];
        
        if ($replyMarkup) {
            $data['reply_markup'] = json_encode($replyMarkup);
        }
        
        return $this->request('sendMessage', $data);
    }
    
    private function editMessage($chatId, $messageId, $text, $replyMarkup = null) {
        $data = [
            'chat_id' => $chatId,
            'message_id' => $messageId,
            'text' => $text,
            'parse_mode' => 'HTML'
        ];
        
        if ($replyMarkup) {
            $data['reply_markup'] = json_encode($replyMarkup);
        }
        
        return $this->request('editMessageText', $data);
    }
    
    private function answerCallback($callbackId, $text = '') {
        return $this->request('answerCallbackQuery', [
            'callback_query_id' => $callbackId,
            'text' => $text
        ]);
    }
    
    private function request($method, $data = []) {
        $url = "https://api.telegram.org/bot{$this->botToken}/{$method}";
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
        $response = curl_exec($ch);
        curl_close($ch);
        return $response;
    }
}
?>