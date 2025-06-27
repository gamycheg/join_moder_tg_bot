<?php
// Основные настройки
define('BOT_TOKEN', '***'); //Вместо звездочек API токен вашего бота
define('BOT_PERMISSIONS', [
    'can_delete_messages' => true,
    'can_restrict_members' => true
]); //права бота на удаление сервисных сообщений
define('CHANNEL_ID', '***'); //вместо *** ИД вашего канала
define('ADMIN_ID', '***'); //ИД глав.админа (не используется)
define('DB_HOST', 'localhost'); //адрес базы данных (если на хостинге оставляем)
define('DB_NAME', '***'); //Название базы данных
define('DB_USER', '***'); //Пользователь базы данных
define('DB_PASS', '***'); //Пароль базы данных
define('ADMINS', [
    2222222222, // 1й админ
    11111111,  // 2й админ
    111111111 // и т.д.
]); //Здесь перечисляем ИД пользователей который будут админами для бота
define('ADMIN_MESSAGE_FORMAT', [
    'parse_mode' => 'HTML',
    'disable_web_page_preview' => true
]); //Форматирование для админских сообщений
// Дополнительные настройки модерации
define('MODERATION_ENABLED', true); // Включить/выключить модерацию
define('DELETE_SERVICE_MESSAGES', true); //Вкл\выкл удаление сервисных сообщений
define('DELETE_PINNED_NOTIFICATIONS', true);//Вкл\выкл удаление сообщений о закреплении
define('MAX_VIOLATIONS', 20); // Макс. нарушений перед баном
define('STOP_WORDS_FILE', 'stopwords.txt'); // Файл со стоп-словами


// Настройки базы данных
$dbHost = 'localhost'; //адрес базы данных (если на хостинге оставляем)
$dbUser = '***'; //Название базы данных
$dbPass = '***'; //Пользователь базы данных
$dbName = '***'; //Пароль базы данных
?>