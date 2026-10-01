<?php
/* Пример конфигурации. Скопируйте в apps/config.php (файл в .gitignore) и заполните.
   Все три приложения подключают его как ../config.php.

   Остальные секреты (токен Telegram-бота, ключ SMS, ключ реле, коды входа)
   хранятся не в файлах, а в таблице настроек каждого приложения
   (psy_settings / shk_settings / sop_settings) и вводятся владельцем в интерфейсе. */

define('DB_HOST', 'localhost');
define('DB_NAME', 'booking');
define('DB_USER', 'booking');
define('DB_PASS', 'change-me');

/* Публичный адрес сайта без завершающего «/». Приложения живут в /zapis/, /shkola/, /soprovod/. */
define('SITE_URL', 'http://localhost:8080');

/* Куда агрегатор складывает ночные дампы (необязательно). */
// define('BACKUP_DIR', __DIR__ . '/../backups/db');
