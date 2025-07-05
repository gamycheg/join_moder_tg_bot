<?php
require_once 'config.php';
require_once 'onchannel.php';
require_once 'request.php';

class TelegramBot {
    private $token;
    private $db;
    private $channelModerator;
    
    public function __construct($db, $token) {
        $this->db = $db;
        $this->token = $token;
        $this->channelModerator = new ChannelModerator($db, $token, CHANNEL_ID);
    }
    
    private function trash_history_save($message,$db){
	    $stmt = $db->prepare("INSERT INTO `trash` (`serialised_response`) VALUES (?)");
        $stmt->bindValue(1, $message, PDO::PARAM_STR);
	    $stmt->execute();
    }
    
    public function handleUpdate($update) {
        //сохраним в историю
        $message = serialize($update);
        $this->trash_history_save($message, $this->db);
        
        try {
            // Сначала проверяем сообщения в канале
            if ($this->isChannelUpdate($update)) {
                $this->channelModerator->handleUpdate($update);
                return;
            }
            
            // Затем обработка заявок
            if (isset($update['chat_join_request'])) {
                $requestHandler = new JoinRequestHandler($this->db, $this->token, CHANNEL_ID);
                $requestHandler->handle($update);
                return;
            }
            
            // Остальная логика бота
            if (isset($update['callback_query'])) {
                $this->handleCallback($update['callback_query']);
            }
            elseif (isset($update['message'])) {
                $this->handleMessage($update['message']);
            } 
        } catch (Exception $e) {
            error_log("Bot error: " . $e->getMessage());
            $this->notifyAdmins("⚠️ Ошибка бота: " . $e->getMessage());
        }
    }

    private function isChannelUpdate($update) {
        // Проверяем, относится ли обновление к каналу
        if (isset($update['channel_post'])) {
            return true;
        }
        
        if (isset($update['message']['chat']['id']) && 
            $update['message']['chat']['id'] == CHANNEL_ID) {
            return true;
        }
        
        return false;
    }
    /*
    private function isRequestRelatedMessage($update) {
        // Если это заявка на вступление
        if (isset($update['chat_join_request'])) {
            return true;
        }
        
        // Если это ответ на капчу или вопрос
        if (isset($update['message']['text'])) {
            $userId = $update['message']['from']['id'];
            
            // Проверяем, есть ли у пользователя активная заявка
            $stmt = $this->db->prepare('SELECT 1 FROM requests 
                                      WHERE user_id = ? AND status = "pending" 
                                      LIMIT 1');
            $stmt->execute([$userId]);
            return (bool)$stmt->fetch();
        }
        
        return false;
    }
    */
    private function handleMessage($message) {
        $text = $message['text'] ?$message['text']: '';
        $chatId = $message['chat']['id'];
        $userId = $message['from']['id'];
    
        // Ваш переработанный блок проверки админа
        if($chatId == $userId) {
            $isAdmin = in_array($userId, ADMINS);
        } else {
            $isAdmin = false;
        }
    
        // Сначала проверяем, не относится ли сообщение к заявкам
        if ($this->isRequestRelatedMessage($message)) {
            require_once 'request.php';
            $requestHandler = new JoinRequestHandler($this->db, $this->token, CHANNEL_ID);
            $requestHandler->handle(['message' => $message]);
            return;
        }
    
        // Обработка команд бота
        switch (true) {
            case strpos($text, '/start') === 0:
                $this->sendWelcomeMessage($chatId);
                break;
                
            case strpos($text, '/help') === 0:
                $this->sendHelpMessage($chatId);
                break;
                
            case strpos($text, '/stats') === 0 && $isAdmin:
                $this->sendStats($chatId);
                break;
                
            // Добавляем другие команды админов перед default
            case strpos($text, '/инфо') === 0 && $isAdmin:
                $this->sendInfo($chatId);
                break;
                
            default:
                if ($isAdmin) {
                    // Если админ отправил неизвестную команду - пересылаем другим админам
                    if(isset($message['text'])){
                        $this->handleAdminBroadcast($message);
                    }
                    else{
                        $this->forwardAdminMedia($message);
                    }
                } else {
                    $this->handleDefaultMessage($chatId, $text);
                }
        }
    }
    
    private function isRequestRelatedMessage($message) {
        if (!isset($message['text'])) return false;
        
        $userId = $message['from']['id'];
        
        // Проверяем, есть ли у пользователя активная заявка
        $stmt = $this->db->prepare('SELECT 1 FROM requests 
                                  WHERE user_id = ? AND status = "pending" 
                                  LIMIT 1');
        $stmt->execute([$userId]);
        return (bool)$stmt->fetch();
    }
    
    private function handleCallback($callback) {
        $data = $callback['data'];
        $chatId = $callback['message']['chat']['id'];
        $messageId = $callback['message']['message_id'];
        
        if (str_starts_with($data, 'approve_') || str_starts_with($data, 'reject_')) {
            $requestHandler = new JoinRequestHandler($this->db, $this->token, CHANNEL_ID);
            $requestHandler->handle(['callback_query' => $callback]);
            return;
        }


        // Обработка callback-действий основного бота
        if (strpos($data, 'main_') === 0) {
            $action = substr($data, 5);
            
            switch ($action) {
                case 'menu':
                    $this->showMainMenu($chatId);
                    break;
                    
                // Другие callback-действия бота
            }
        }
        
        $this->answerCallback($callback['id']);
    }
    
    private function sendWelcomeMessage($chatId) {
        $message = "👋 Добро пожаловать в наш бот!\n\n";
        $message .= "Здесь вы можете подать заявку на вступление в наш канал.";
        
        $this->sendMessage($chatId, $message, [
            'keyboard' => [
                ['📝 Подать заявку'],
                ['ℹ️ Информация']
            ],
            'resize_keyboard' => true
        ]);
    }
    
    private function sendHelpMessage($chatId) {
        $message = "📌 Доступные команды:\n\n";
        $message .= "/start - Начало работы\n";
        $message .= "/help - Эта справка\n";
        $message .= "\nДля подачи заявки на вступление нажмите '📝 Подать заявку'";
        
        $this->sendMessage($chatId, $message);
    }
    
    private function sendStats($chatId) {
        try {
            $stmt = $this->db->query("SELECT 
                COUNT(*) as total_requests,
                SUM(status = 'approved') as approved,
                SUM(status = 'rejected') as rejected,
                SUM(status = 'pending') as pending
                FROM requests");
            $stats = $stmt->fetch();
            
            $message = "📊 Статистика заявок:\n\n";
            $message .= "Всего заявок: {$stats['total_requests']}\n";
            $message .= "Одобрено: {$stats['approved']}\n";
            $message .= "Отклонено: {$stats['rejected']}\n";
            $message .= "В ожидании: {$stats['pending']}";
            
            $this->sendMessage($chatId, $message);
        } catch (PDOException $e) {
            $this->sendMessage($chatId, "⚠️ Ошибка получения статистики");
            error_log("Stats error: " . $e->getMessage());
        }
    }
    
    private function handleDefaultMessage($chatId, $text) {
        // Обработка обычных текстовых сообщений
        if ($text === '📝 Подать заявку') {
            $this->sendMessage($chatId, "Чтобы подать заявку, попробуйте присоединиться к нашему каналу @" . str_replace('@', '', CHANNEL_ID) . " и подтвердите вступление");
        } elseif ($text === 'ℹ️ Информация') {
            $this->sendHelpMessage($chatId);
        } else {
            if($chatId != CHANNEL_ID){
                $this->sendMessage($chatId, "Не понимаю ваше сообщение. Используйте /help для справки.");
            }
        }
    }
    
    private function handleAdminBroadcast($message) {
        $senderId = $message['from']['id'];
        $senderName = $this->getUserName($message['from']);
    
        // Формируем сообщение для админов
        $adminMessage = "📨 Сообщение от админа $senderName:\n\n";
        $adminMessage .= $message['text'];
    
        // Отправляем всем админам, кроме отправителя
        foreach (ADMINS as $adminId) {
            if ($adminId != $senderId) {
                $this->sendMessage($adminId, $adminMessage);
            }
        }
    
        // Подтверждение отправителю
        $this->sendMessage($senderId, "✅ Ваше сообщение отправлено другим администраторам");
    }

    private function notifyAdmins($message){
        // Формируем сообщение для админов
        $adminMessage = $message;
    
        // Отправляем всем админам, кроме отправителя
        foreach (ADMINS as $adminId) {
            if ($adminId != $senderId) {
                $this->sendMessage($adminId, $adminMessage);
            }
        }
    }
	
    private function getUserName($user) {
        $name = $user['first_name'] ?$user['first_name']: '';
        if (isset($user['last_name'])) {
            $name .= ' ' . $user['last_name'];
        }
        if (isset($user['username'])) {
            $name .= ' (@' . $user['username'] . ')';
        }
        return trim($name);
    }

    private function forwardAdminMedia($message) {
        $senderId = $message['from']['id'];
        $senderName = $this->getUserName($message['from']);
        
        // Отправляем всем админам, кроме отправителя
        foreach (ADMINS as $adminId) {
            if ($adminId != $senderId) {
                $this->forwardMessage($adminId, $message['chat']['id'], $message['message_id']);
            }
        }

        // Подтверждение отправителю
        $this->sendMessage($senderId, "✅ Ваше сообщение переслано другим администраторам");
    }

    private function forwardMessage($chatId, $fromChatId, $messageId) {
        $this->request('forwardMessage', [
            'chat_id' => $chatId,
            'from_chat_id' => $fromChatId,
            'message_id' => $messageId
        ]);
    }

    private function showMainMenu($chatId) {
        $this->sendMessage($chatId, "Выберите действие:", [
            'inline_keyboard' => [
                [['text' => '📊 Статистика', 'callback_data' => 'main_stats']],
                [['text' => '🆘 Помощь', 'callback_data' => 'main_help']]
            ]
        ]);
    }
    
    public function sendMessage($chatId, $text, $replyMarkup = null) {
        $data = [
            'chat_id' => $chatId,
            'text' => $text,
            'parse_mode' => 'HTML'
        ];
        
        if ($replyMarkup) {
            if (isset($replyMarkup['keyboard'])) {
                $data['reply_markup'] = json_encode([
                    'keyboard' => $replyMarkup['keyboard'],
                    'resize_keyboard' => $replyMarkup['resize_keyboard'] ?$replyMarkup['resize_keyboard']: true,
                    'one_time_keyboard' => $replyMarkup['one_time_keyboard'] ?$replyMarkup['one_time_keyboard']: false
                ]);
            } else {
                $data['reply_markup'] = json_encode($replyMarkup);
            }
        }
        
        $this->request('sendMessage', $data);
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
        
        $this->request('editMessageText', $data);
    }
    
    private function answerCallback($callbackId, $text = '') {
        $this->request('answerCallbackQuery', [
            'callback_query_id' => $callbackId,
            'text' => $text
        ]);
    }
    
    private function request($method, $data = []) {
        $url = "https://api.telegram.org/bot{$this->token}/{$method}";
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
        $response = curl_exec($ch);
        curl_close($ch);
        return $response;
    }
}

// Инициализация базы данных
function initDB() {
    try {
        $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4";
        $pdo = new PDO($dsn, DB_USER, DB_PASS);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        return $pdo;
    } catch (PDOException $e) {
        error_log("Database connection error: " . $e->getMessage());
        die("Database error");
    }
}

// Основной поток выполнения
$update = json_decode(file_get_contents('php://input'), true);
$db = initDB();
$db->exec("CREATE TABLE IF NOT EXISTS `trash` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `timenow` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `serialised_response` TEXT COLLATE utf8mb4_general_ci
)");
        $db->exec("CREATE TABLE IF NOT EXISTS requests (
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
        
        $db->exec("CREATE TABLE IF NOT EXISTS interactions (
            id INT AUTO_INCREMENT PRIMARY KEY,
            request_id INT NOT NULL,
            message_text TEXT,
            is_bot_message TINYINT(1),
            message_date TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (request_id) REFERENCES requests(id) ON DELETE CASCADE,
            INDEX idx_request_id (request_id)
        )");
        
        $db->exec("CREATE TABLE IF NOT EXISTS answers (
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
        
        $db->exec("CREATE TABLE IF NOT EXISTS admin_messages (
            id INT AUTO_INCREMENT PRIMARY KEY,
            request_id INT NOT NULL,
            admin_id BIGINT NOT NULL,
            message_id INT NOT NULL,
            FOREIGN KEY (request_id) REFERENCES requests(id) ON DELETE CASCADE,
            UNIQUE KEY unique_admin_message (request_id, admin_id)
        )");
$bot = new TelegramBot($db, BOT_TOKEN);
$bot->handleUpdate($update);

http_response_code(200);
?>
