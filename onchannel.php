<?php
class ChannelModerator {
    private $db;
    private $botToken;
    private $channelId;
    private $stopWords = [];
    
    public function __construct($db, $botToken, $channelId) {
        $this->db = $db;
        $this->botToken = $botToken;
        $this->channelId = $channelId;
        $this->loadStopWords();
        $this->initDB();
    }
    
    private function initDB() {
        try {
            $this->db->exec("CREATE TABLE IF NOT EXISTS deleted_messages (
                id INT AUTO_INCREMENT PRIMARY KEY,
                message_id INT NOT NULL,
                user_id BIGINT NOT NULL,
                reason VARCHAR(255),
                deleted_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                message_text VARCHAR(3000),
                INDEX idx_message (message_id),
                INDEX idx_user (user_id)
            )");
            
            $this->db->exec("CREATE TABLE IF NOT EXISTS violators (
                id INT AUTO_INCREMENT PRIMARY KEY,
                user_id BIGINT NOT NULL,
                username VARCHAR(255),
                first_name VARCHAR(255),
                last_name VARCHAR(255),
                violations INT DEFAULT 1,
                last_violation TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY unique_user (user_id)
            )");
        } catch (PDOException $e) {
            error_log("Database init error: " . $e->getMessage());
            throw new Exception("Could not initialize database tables");
        }
    }
    
    private function loadStopWords() {
        if (defined('STOP_WORDS_FILE') && file_exists(STOP_WORDS_FILE)) {
            $words = file(STOP_WORDS_FILE, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            $this->stopWords = array_filter($words);
        } else {
            // Стандартный набор, если файл не найден
            $this->stopWords = [
                'блядь', 'блять', 'ебанный', 'ёбанный'
            ];
        }
    }
    
    public function handleUpdate($update) {
        if (!MODERATION_ENABLED) return;
        
        try {
            if (!$this->isChannelMessage($update)) return;
            
            if (isset($update['channel_post'])) {
                $this->handleChannelPost($update['channel_post']);
            } elseif (isset($update['message'])) {
                $this->handleMessage($update['message']);
            }
        } catch (Exception $e) {
            error_log("Channel moderation error: " . $e->getMessage());
            $this->notifyAdmins("⚠️ Ошибка модерации: " . $e->getMessage());
        }
    }
    
    private function isChannelMessage($update) {
        if (isset($update['channel_post'])) return true;
        
        if (isset($update['message']['chat']['id'])) {
            $chatId = $update['message']['chat']['id'];
            $channelId = str_replace('@', '', $this->channelId);
            return $chatId == $channelId || $chatId == $this->channelId;
        }
        
        return false;
    }
    
    private function handleChannelPost($post) {
        // Удаляем технические сообщения о новых участниках/выходах
        if (isset($post['new_chat_member']) || isset($post['left_chat_member'])) {
            $this->deleteServiceMessage($post['message_id']);
            return;
        }
    
        // Удаляем сообщения о закрепленных сообщениях
        if (isset($post['pinned_message'])) {
            $this->deleteServiceMessage($post['message_id']);
            return;
        }
    
        // Проверяем текст на стоп-слова
        if (isset($post['text'])) {
            $this->checkMessageContent($post);
        }
    }
    
    private function handleMessage($message) {
        if (isset($message['new_chat_members'])) {
            $this->deleteServiceMessage($message['message_id']);
            return;
        }

        // Обработка вышедших участников (приходит в message.left_chat_member)
        if (isset($message['left_chat_member'])) {
            $this->deleteServiceMessage($message['message_id']);
            return;
        }
        
        // Удаляем сообщения о закрепленных сообщениях
        if (isset($message['pinned_message'])) {
            $this->deleteServiceMessage($message['message_id']);
            return;
        }
        
        $this->checkMessageContent($message);
    }
    
    private function checkMessageContent($message) {
        $text = mb_strtolower((isset($message['text']) and $message['text']!='')?$message['text']: '');
        $foundWords = $this->detectStopWords($text);
    
        if (!empty($foundWords)) {
            $this->processViolation($message, $foundWords);
        }
        // Дополнительно проверяем на технические сообщения
        elseif ($this->isTechnicalMessage($text)) {
            $this->deleteServiceMessage($message['message_id']);
        }
    }
    
    private function isTechnicalMessage($text) {
        $technicalPatterns = [
            '/присоединился к каналу/i',
            '/покинул канал/i',
            '/закрепил сообщение/i',
            '/pinned a message/i',
            '/added to channel/i',
            '/left the channel/i'
        ];
    
        foreach ($technicalPatterns as $pattern) {
            if (preg_match($pattern, $text)) {
                return true;
            }
        }
        return false;
    }
    
    private function detectStopWords($text) {
        $found = [];
        foreach ($this->stopWords as $word) {
            if (mb_strpos($text, mb_strtolower($word)) !== false) {
                $found[] = $word;
            }
        }
        return $found;
    }
    
    private function processViolation($message, $stopWords) {
        // Удаляем сообщение
        $this->deleteMessage($message['message_id'], 'stop_word', $stopWords, $message['text'], $message['from']['id']?$message['from']['id']:0);
        
        // Записываем нарушителя
        $this->recordViolation($message['from'] ?$message['from']: null);
        
        // Уведомляем админов
        $this->sendViolationAlert($message, $stopWords);
    }
    
    private function deleteServiceMessage($messageId) {
        try {
            $response = $this->request('deleteMessage', [
                'chat_id' => $this->channelId,
                'message_id' => $messageId
            ]);
    
            $responseData = json_decode($response, true);
            
            if (!$responseData || !$responseData['ok']) {
                error_log("Failed to delete service message: " . print_r($responseData, true));
                $this->notifyAdmins("⚠️ Не удалось удалить техническое сообщение: " . $messageId);
            }
        } catch (Exception $e) {
            error_log("Delete service message error: " . $e->getMessage());
            $this->notifyAdmins("⚠️ Ошибка удаления технического сообщения: " . $e->getMessage());
        }
    }
    
    private function deleteMessage($messageId, $reason, $details = null, $text=null,$userId=0) {
        try {
            $this->request('deleteMessage', [
                'chat_id' => $this->channelId,
                'message_id' => $messageId
            ]);
            
            $this->db->prepare('INSERT INTO deleted_messages 
                              (message_id, user_id, reason,message_text) 
                              VALUES (?, ?, ?, ?)')
                   ->execute([$messageId, $userId, $reason, $text]);
            
        } catch (Exception $e) {
            error_log("Delete message failed: " . $e->getMessage());
        }
    }
    
    private function recordViolation($user) {
        if (!$user || !isset($user['id'])) return;
        
        try {
            $stmt = $this->db->prepare('INSERT INTO violators 
                                      (user_id, username, first_name, last_name) 
                                      VALUES (?, ?, ?, ?)
                                      ON DUPLICATE KEY UPDATE 
                                      violations = violations + 1,
                                      last_violation = CURRENT_TIMESTAMP');
            $stmt->execute([
                $user['id'],
                $user['username'] ?$user['username']: null,
                $user['first_name'] ?$user['first_name']: null,
                $user['last_name'] ?$user['last_name']: null
            ]);
            
            // Проверяем на превышение лимита нарушений
            $this->checkViolationsLimit($user['id']);
            
        } catch (PDOException $e) {
            error_log("Violation record error: " . $e->getMessage());
        }
    }
    
    private function checkViolationsLimit($userId) {
        $stmt = $this->db->prepare('SELECT violations FROM violators WHERE user_id = ?');
        $stmt->execute([$userId]);
        $result = $stmt->fetch();
        
        if ($result && $result['violations'] >= MAX_VIOLATIONS) {
            $this->banUser($userId);
        }
    }
    
    private function banUser($userId) {
        try {
            $this->request('banChatMember', [
                'chat_id' => $this->channelId,
                'user_id' => $userId
            ]);
            
            $this->notifyAdmins("⛔ Пользователь ID {$userId} забанен за превышение лимита нарушений");
            
        } catch (Exception $e) {
            error_log("Ban user failed: " . $e->getMessage());
        }
    }
    
    private function sendViolationAlert($message, $stopWords) {
        $messageLink = $this->getMessageLink($message);
        $user = $message['from'] ?$message['from']: ['id' => 'unknown', 'username' => 'unknown'];
        
        $text = "🚨 Нарушение в канале!\n\n";
        $text .= "🔗 Сообщение: {$messageLink}\n";
        $text .= "👤 Пользователь: @" . ($user['username'] ?$user['username']: 'неизвестен') . "\n";
        $text .= "🆔 ID: " . ($user['id'] ?$user['id']: 'неизвестен') . "\n";
        $text .= "🔞 Нарушения: " . implode(', ', $stopWords) . "\n\n";
        $text .= "Сообщение было автоматически удалено.";
        
        $this->notifyAdmins($text);
    }
    
    private function getMessageLink($message) {
        $channelUsername = str_replace('@', '', $this->channelId);
        return "https://t.me/{$channelUsername}/{$message['message_id']}";
    }
    
    private function notifyAdmins($text) {
        foreach (ADMINS as $adminId) {
            $this->sendMessage($adminId, $text, [
                'parse_mode' => 'HTML',
                'disable_web_page_preview' => true
            ]);
        }
    }
    
    private function sendMessage($chatId, $text, $params = []) {
        $data = array_merge([
            'chat_id' => $chatId,
            'text' => $text
        ], $params);
        
        return $this->request('sendMessage', $data);
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
