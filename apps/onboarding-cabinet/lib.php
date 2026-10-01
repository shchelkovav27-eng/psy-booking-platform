<?php
/* ═══════════════════════════════════════════════════════════════
   Сопровождение в профессию — общее ядро.

   Третий контур рядом со школой и агрегатором: менеджеры онбординга
   созваниваются с новыми ученицами школы и вводят их в профессию.
   Данные: MySQL, та же база, но только таблицы sop_* — чужие таблицы
   система не трогает. Единственное исключение — импорт СЧИТЫВАЕТ
   shk_clients (и ничего туда не пишет): список новых учениц уже
   ведёт школа, перебивать его руками было бы двойной работой.
   Секреты БД берём из ../config.php — отдельно ничего не заводим.
   ═══════════════════════════════════════════════════════════════ */

if (!defined('SOP_LIB')) { define('SOP_LIB', 1); }

require_once __DIR__ . '/../config.php';

mb_internal_encoding('UTF-8');

/* ── База ───────────────────────────────────────────────────── */
function db(){
  static $pdo;
  if(!$pdo){
    $pdo = new PDO('mysql:host='.DB_HOST.';dbname='.DB_NAME.';charset=utf8mb4', DB_USER, DB_PASS, [
      PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
      PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
      PDO::ATTR_EMULATE_PREPARES   => false,
    ]);
  }
  return $pdo;
}
function q($sql, $args = []){ $st = db()->prepare($sql); $st->execute($args); return $st; }
function one($sql, $args = []){ $r = q($sql,$args)->fetch(); return $r === false ? null : $r; }
function all($sql, $args = []){ return q($sql,$args)->fetchAll(); }

function initTables(){
  static $done; if($done) return; $done = 1;
  $e = 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4';
  db()->exec("CREATE TABLE IF NOT EXISTS sop_settings(
    k VARCHAR(40) PRIMARY KEY, v TEXT) $e");
  /* Люди и вход — как в школе: у всех одинаково, почта и пароль.
     Ученице аккаунт открывается сразу (по почте/телефону подклеиваем её
     к списку из школы), менеджеру — после подтверждения владельцем:
     это доступ к чужим персональным данным. */
  db()->exec("CREATE TABLE IF NOT EXISTS sop_users(
    id INT AUTO_INCREMENT PRIMARY KEY,
    email VARCHAR(120) NOT NULL UNIQUE,
    pass VARCHAR(255) NOT NULL,
    name VARCHAR(120) NOT NULL,
    role VARCHAR(10) NOT NULL,
    status VARCHAR(10) NOT NULL DEFAULT 'active',
    client_id INT NULL,
    phone VARCHAR(30) DEFAULT '',
    tg VARCHAR(60) DEFAULT '',
    link VARCHAR(300) DEFAULT '',
    tg_key VARCHAR(16) NOT NULL,
    tg_chat BIGINT NULL,
    reset_token CHAR(32) NULL,
    reset_at DATETIME NULL,
    created_at DATETIME NOT NULL,
    last_at DATETIME NULL,
    KEY k_status (status, role)) $e");
  /* Ученицы. Контакты приезжают импортом из школы, а всё про онбординг —
     статус, счётчики, ключ личной ссылки — живёт только здесь. */
  db()->exec("CREATE TABLE IF NOT EXISTS sop_clients(
    id INT AUTO_INCREMENT PRIMARY KEY,
    ckey VARCHAR(120) NOT NULL UNIQUE,
    name VARCHAR(120) NOT NULL,
    email VARCHAR(120) DEFAULT '',
    phone VARCHAR(30) DEFAULT '',
    tg VARCHAR(60) DEFAULT '',
    contact VARCHAR(120) DEFAULT '',
    course VARCHAR(300) DEFAULT '',
    status VARCHAR(10) NOT NULL DEFAULT 'new',
    done_at DATETIME NULL,
    done_by VARCHAR(120) DEFAULT '',
    noshow_cnt INT DEFAULT 0,
    moved_cnt INT DEFAULT 0,
    note VARCHAR(400) DEFAULT '',
    kkey VARCHAR(16) NOT NULL,
    tg_chat BIGINT NULL,
    tg_key VARCHAR(16) NOT NULL,
    imported_at DATETIME NULL,
    created_at DATETIME NOT NULL,
    KEY k_status (status)) $e");
  db()->exec("CREATE TABLE IF NOT EXISTS sop_slots(
    id INT AUTO_INCREMENT PRIMARY KEY,
    manager_id INT NOT NULL,
    dt DATETIME NOT NULL,
    booking_id INT NULL,
    link VARCHAR(300) DEFAULT '',
    created_at DATETIME NOT NULL,
    UNIQUE KEY uniq_slot (manager_id, dt),
    KEY k_dt (dt)) $e");
  /* Запись на созвон. Отчёт менеджера лежит прямо в записи (rep_*):
     из этих отчётов складывается анамнез в карточке ученицы. */
  db()->exec("CREATE TABLE IF NOT EXISTS sop_bookings(
    id INT AUTO_INCREMENT PRIMARY KEY,
    code VARCHAR(8) NOT NULL UNIQUE,
    client_id INT NOT NULL,
    manager_id INT NOT NULL,
    slot_id INT NOT NULL,
    dt DATETIME NOT NULL,
    link VARCHAR(300) DEFAULT '',
    status VARCHAR(12) DEFAULT 'booked',
    rep_note TEXT NULL,
    rep_status VARCHAR(200) DEFAULT '',
    rep_next VARCHAR(400) DEFAULT '',
    rep_next_dt DATE NULL,
    rep_at DATETIME NULL,
    created_at DATETIME NOT NULL,
    KEY k_dt (dt), KEY k_cli (client_id), KEY k_man (manager_id)) $e");
  /* Лента событий по ученице: записалась, провели, не пришла, перенесли,
     завершили. Из неё в карточке видна вся дорога онбординга. */
  db()->exec("CREATE TABLE IF NOT EXISTS sop_history(
    id INT AUTO_INCREMENT PRIMARY KEY,
    client_id INT NOT NULL,
    booking_id INT NULL,
    at DATETIME NOT NULL,
    kind VARCHAR(20) NOT NULL,
    txt VARCHAR(500) NOT NULL,
    KEY k_c (client_id), KEY k_at (at)) $e");
  db()->exec("CREATE TABLE IF NOT EXISTS sop_tokens(
    token CHAR(32) PRIMARY KEY,
    role VARCHAR(10) NOT NULL,
    user_id INT NULL,
    created_at DATETIME NOT NULL,
    last_used DATETIME NOT NULL) $e");
  db()->exec("CREATE TABLE IF NOT EXISTS sop_login(
    ip VARCHAR(45) PRIMARY KEY, fails INT DEFAULT 0, blocked_until DATETIME NULL) $e");
  /* Очередь для Telegram: хостинг наружу к api.telegram.org не пускает,
     сообщения забирает и доставляет реле с компьютера владельца. */
  db()->exec("CREATE TABLE IF NOT EXISTS sop_outbox(
    id INT AUTO_INCREMENT PRIMARY KEY,
    chat BIGINT NOT NULL,
    text TEXT NOT NULL,
    kb TEXT NULL,
    status VARCHAR(10) DEFAULT 'pending',
    tries INT DEFAULT 0,
    err VARCHAR(300) NULL,
    created_at DATETIME NOT NULL,
    sent_at DATETIME NULL,
    KEY k_status (status)) $e");
  /* Что уже отправляли, чтобы не слать дважды. ref_id — запись или
     ученица, роды различаются самим kind ('r24' про запись, 'stale' про ученицу). */
  db()->exec("CREATE TABLE IF NOT EXISTS sop_sent(
    ref_id INT NOT NULL, kind VARCHAR(20) NOT NULL, at DATETIME NOT NULL,
    via VARCHAR(10) DEFAULT '',
    PRIMARY KEY (ref_id, kind)) $e");
  db()->exec("CREATE TABLE IF NOT EXISTS sop_tg(
    chat BIGINT PRIMARY KEY,
    role VARCHAR(10) NOT NULL,
    user_id INT NULL,
    client_id INT NULL,
    booking_id INT NULL,
    state VARCHAR(30) DEFAULT '',
    uname VARCHAR(64) DEFAULT '',
    fname VARCHAR(120) DEFAULT '',
    updated_at DATETIME NOT NULL) $e");
  db()->exec("CREATE TABLE IF NOT EXISTS sop_log(
    id INT AUTO_INCREMENT PRIMARY KEY, at DATETIME NOT NULL,
    kind VARCHAR(20), txt VARCHAR(500)) $e");
}

/* Доп. колонки, если появятся позже, добавляем здесь на лету — чтобы
   обновление кода никогда не требовало руками лезть в базу. */
function migrate(){
  static $done; if($done) return; $done = 1;
  /* По дороге вход дважды перестраивался (почта→пины→снова почта, как в
     школе). От прежних попыток могли остаться таблицы старой формы —
     боевых данных в них не было, поэтому просто пересоздаём. */
  $staryjTokens = one("SHOW COLUMNS FROM sop_tokens LIKE 'manager_id'");
  if(one("SHOW TABLES LIKE 'sop_managers'") || $staryjTokens){
    db()->exec("DROP TABLE IF EXISTS sop_managers");
    db()->exec("DROP TABLE IF EXISTS sop_tokens");
    db()->exec("DROP TABLE IF EXISTS sop_tg");
    db()->exec("DELETE FROM sop_slots");
    db()->exec("DELETE FROM sop_bookings");
    /* пересоздаём убранные таблицы уже в новой форме */
    $e = 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4';
    db()->exec("CREATE TABLE IF NOT EXISTS sop_tokens(
      token CHAR(32) PRIMARY KEY,
      role VARCHAR(10) NOT NULL,
      user_id INT NULL,
      created_at DATETIME NOT NULL,
      last_used DATETIME NOT NULL) $e");
    db()->exec("CREATE TABLE IF NOT EXISTS sop_tg(
      chat BIGINT PRIMARY KEY,
      role VARCHAR(10) NOT NULL,
      user_id INT NULL,
      client_id INT NULL,
      booking_id INT NULL,
      state VARCHAR(30) DEFAULT '',
      uname VARCHAR(64) DEFAULT '',
      fname VARCHAR(120) DEFAULT '',
      updated_at DATETIME NOT NULL) $e");
  }
  /* Фото в профиле ученицы появилось позже самой таблицы */
  $haveC = [];
  foreach(all("SHOW COLUMNS FROM sop_clients") as $c) $haveC[$c['Field']] = 1;
  if(!isset($haveC['photo'])) db()->exec("ALTER TABLE sop_clients ADD COLUMN photo VARCHAR(120) DEFAULT ''");
  /* Отказ от созвонов — нормальный исход (заказчица процесса, созвон 25.08): ученица
     сама не хочет, мы это помним, не дёргаем её приглашениями, но
     сертификата не лишаем. */
  if(!isset($haveC['refused'])) db()->exec("ALTER TABLE sop_clients ADD COLUMN refused TINYINT DEFAULT 0");
  /* Итоги созвона для ученицы — отдельно от внутреннего отчёта: их она
     видит у себя в кабинете. */
  $haveB = [];
  foreach(all("SHOW COLUMNS FROM sop_bookings") as $c) $haveB[$c['Field']] = 1;
  if(!isset($haveB['rep_pub'])) db()->exec("ALTER TABLE sop_bookings ADD COLUMN rep_pub TEXT NULL");
  /* Поля из таблицы заказчицы процесса (этап 2): старт 10-дневного цикла, уроки и
     прирост, бонус-курс, ссылки на Омнидеск и сделку, причина возврата. */
  $add = [
    'start_dt'      => 'DATE NULL',
    'bonus'         => "VARCHAR(200) DEFAULT ''",
    'omni_url'      => "VARCHAR(300) DEFAULT ''",
    'deal_url'      => "VARCHAR(300) DEFAULT ''",
    'lessons_start' => 'INT NULL',
    'lessons_d5'    => 'INT NULL',
    'lessons_end'   => 'INT NULL',
    'refund_reason' => "VARCHAR(60) DEFAULT ''",
    'refund_at'     => 'DATETIME NULL',
    'held_manual'   => 'INT DEFAULT 0',   /* созвоны, проведённые до переезда из таблицы */
  ];
  foreach($add as $col => $def){
    if(!isset($haveC[$col])) db()->exec("ALTER TABLE sop_clients ADD COLUMN `$col` $def");
  }
  /* Пары менеджеров (график 2/2, с 26.08): владелец объединяет двух
     менеджеров общей меткой пары, куратор закрепляет ученицу за парой —
     дальше её видит и ведёт именно эта пара, до выпуска. */
  $haveU = [];
  foreach(all("SHOW COLUMNS FROM sop_users") as $c) $haveU[$c['Field']] = 1;
  if(!isset($haveU['pair_name'])) db()->exec("ALTER TABLE sop_users ADD COLUMN pair_name VARCHAR(60) DEFAULT ''");
  if(!isset($haveC['pair_name'])) db()->exec("ALTER TABLE sop_clients ADD COLUMN pair_name VARCHAR(60) DEFAULT ''");
}

/* Блоки статистики, которые владелец может включать и выключать —
   переключателями, без произвольных новых метрик (заказчица процесса, 29.08). */
function STATS_BLOCKS(){
  return [
    'top'      => 'Верхние итоги (всего, в работе, возвраты…)',
    'period'   => 'За период (новых, созвонов, возвратов…)',
    'compare'  => 'Сравнение с предыдущим периодом',
    'lessons'  => 'Уроки (просмотрено, прирост)',
    'touches'  => 'Проведено касаний по дням цикла',
    'stages'   => 'Сопровождение — 10-дневный цикл',
    'course'   => 'По курсу',
    'reason'   => 'По причине возврата',
    'managers' => 'По менеджерам',
    'stale'    => 'Зависшие и отказавшиеся',
  ];
}

/* Пары менеджеров — список меток, которые сейчас в ходу. */
function pairsList(){
  $rows = all("SELECT DISTINCT pair_name FROM sop_users
               WHERE role='manager' AND status='active' AND pair_name<>'' ORDER BY pair_name");
  return array_column($rows, 'pair_name');
}
/* Данные новой ученицы считаем незаполненными, если нечем с ней связаться —
   это и просили подсвечивать (заказчица процесса, 29.08). */
function clientMissingData($c){
  return $c['status'] === 'new' && trim((string)$c['phone']) === '' && trim((string)$c['tg']) === '';
}

/* ── Настройки ──────────────────────────────────────────────── */
function defaults(){
  return [
    'org'          => 'Сопровождение в профессию',
    'tz'           => 'Europe/Moscow',
    'duration'     => '30',      /* сколько идёт созвон, минут: 15–30 у менеджеров */
    'target_calls' => '5',       /* план онбординга: 5 созвонов за 10 дней (0-3-5-7-10) */
    'cert_note'    => 'Поздравляем! Вы прошли 10 дней лёгкого старта. Менеджер пришлёт вам сертификат и мини-курс в подарок на выбор.',
    'stats_blocks' => '',        /* какие блоки статистики показывать; пусто — все */
    'remind24'     => '1',       /* напоминание за сутки */
    'remind2'      => '1',       /* напоминание за два часа */
    'courses_allow'=> '',        /* какие курсы берём в онбординг, по строке на курс; пусто — все */
    'auto_import'  => '1',       /* подтягивать новых учениц из школы раз в сутки */
    'import_from'  => '',        /* брать из школы только тех, кто появился там с этой даты; пусто — всех */
    'stale_days'   => '7',       /* через сколько дней без записи считать ученицу «зависшей» */
    'alert_email'  => '',        /* почта владельца для тревог и заявок */
    'tg_token'     => '',
    'bot_user'     => '',
    'relay_key'    => '',
    'site_url'     => (defined('SITE_URL') ? SITE_URL : 'https://booking.example.com') . '/soprovod/',
    'last_relay'   => '',
    'tg_offset'    => '0',
    'last_tick'    => '',
    'last_daily'   => '',
  ];
}
$SOP_S = null;
function settings(){
  global $SOP_S;
  if($SOP_S) return $SOP_S;
  initTables();
  migrate();
  $SOP_S = defaults();
  foreach(all("SELECT k,v FROM sop_settings") as $r) $SOP_S[$r['k']] = $r['v'];
  if($SOP_S['relay_key'] === ''){ $SOP_S['relay_key'] = bin2hex(random_bytes(12)); setSetting('relay_key', $SOP_S['relay_key']); }
  date_default_timezone_set($SOP_S['tz'] ?: 'Europe/Moscow');
  return $SOP_S;
}
/* Пишем и в базу, и в уже загруженный набор — иначе код после сохранения
   продолжал бы видеть старое значение до конца запроса. */
function setSetting($k, $v){
  global $SOP_S;
  q("INSERT INTO sop_settings(k,v) VALUES(?,?) ON DUPLICATE KEY UPDATE v=VALUES(v)", [$k, (string)$v]);
  if(is_array($SOP_S)) $SOP_S[$k] = (string)$v;
  if($k === 'tz' && $v) date_default_timezone_set((string)$v);
  return true;
}
function cfg($k){ $s = settings(); return $s[$k] ?? ''; }

/* ── Мелочи ─────────────────────────────────────────────────── */
function now(){ settings(); return date('Y-m-d H:i:s'); }
function ts($dt){ return strtotime($dt); }
function rnd($n = 32){ return substr(bin2hex(random_bytes($n)), 0, $n); }
function newCode(){
  $a = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
  for($try = 0; $try < 40; $try++){
    $c = '';
    for($i = 0; $i < 5; $i++) $c .= $a[random_int(0, strlen($a) - 1)];
    if(!one("SELECT code FROM sop_bookings WHERE code=?", [$c])) return $c;
  }
  return strtoupper(substr(bin2hex(random_bytes(4)), 0, 6));
}
function logit($kind, $txt){
  try{ q("INSERT INTO sop_log(at,kind,txt) VALUES(?,?,?)", [now(), $kind, mb_substr($txt, 0, 480)]); }catch(Throwable $e){}
}
function fmtRu($dt){
  $days  = ['Sunday'=>'воскресенье','Monday'=>'понедельник','Tuesday'=>'вторник','Wednesday'=>'среда',
            'Thursday'=>'четверг','Friday'=>'пятница','Saturday'=>'суббота'];
  $mon   = [1=>'января',2=>'февраля',3=>'марта',4=>'апреля',5=>'мая',6=>'июня',7=>'июля',
            8=>'августа',9=>'сентября',10=>'октября',11=>'ноября',12=>'декабря'];
  $t = ts($dt);
  return $days[date('l', $t)] . ', ' . (int)date('j', $t) . ' ' . $mon[(int)date('n', $t)] . ', ' . date('H:i', $t);
}
function phoneOf($contact){
  $d = preg_replace('/\D/', '', (string)$contact);
  if(strlen($d) === 11 && ($d[0] === '8' || $d[0] === '7')) return '7' . substr($d, 1);
  if(strlen($d) === 10) return '7' . $d;
  return '';
}
function siteUrl(){
  $u = cfg('site_url');
  return $u ?: (defined('SITE_URL') ? SITE_URL : 'https://booking.example.com') . '/soprovod/';
}

/* Менеджеры часто вставляют ссылку на комнату (Телемост и т.п.) без
   "https://" — вставляют то, что видно в адресной строке или прислали
   в чате. Без схемы браузер трактует её как относительный путь на нашем
   же сайте — кнопка "Перейти на созвон" открывает несуществующую
   страницу и с виду просто ничего не делает. Достраиваем схему сами. */
function normUrl($url){
  $url = trim((string)$url);
  if($url === '') return '';
  if(!preg_match('~^[a-z][a-z0-9+.-]*://~i', $url)) $url = 'https://' . ltrim($url, '/');
  return $url;
}

/* Проверка на явные вставки не туда: почту вместо ссылки (кто-то скопировал
   не из того окна) и адрес нашего же сайта (перепутали вкладку в браузере —
   это уже случалось на бою). Возвращает текст ошибки или '' если всё ок. */
function urlMistake($rawInput, $normalized){
  if($normalized === '') return '';
  if(preg_match('~^[^@\s]+@[^@\s]+\.[^@\s]+$~', trim((string)$rawInput)))
    return 'Это похоже на почту, а не на ссылку на созвон.';
  $host = parse_url($normalized, PHP_URL_HOST);
  $selfHost = parse_url(siteUrl(), PHP_URL_HOST);
  if($host && $selfHost && strcasecmp($host, $selfHost) === 0)
    return 'Это ссылка на саму систему сопровождения, а не на комнату для звонка (Телемост и т.п.).';
  return '';
}

/* ── Авторизация ────────────────────────────────────────────── */
function clientIp(){ return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0'; }
function loginBlocked(){
  $r = one("SELECT * FROM sop_login WHERE ip=?", [clientIp()]);
  return $r && $r['blocked_until'] && ts($r['blocked_until']) > time();
}
function loginFail(){
  $ip = clientIp();
  $r  = one("SELECT * FROM sop_login WHERE ip=?", [$ip]);
  $f  = ($r ? (int)$r['fails'] : 0) + 1;
  $bu = $f >= 7 ? date('Y-m-d H:i:s', time() + 900) : null;
  q("INSERT INTO sop_login(ip,fails,blocked_until) VALUES(?,?,?)
     ON DUPLICATE KEY UPDATE fails=VALUES(fails), blocked_until=VALUES(blocked_until)", [$ip, $f, $bu]);
}
function loginOk(){ q("DELETE FROM sop_login WHERE ip=?", [clientIp()]); }

function issueToken($role, $userId = null){
  $t = rnd(32);
  q("INSERT INTO sop_tokens(token,role,user_id,created_at,last_used) VALUES(?,?,?,?,?)",
    [$t, $role, $userId, now(), now()]);
  q("DELETE FROM sop_tokens WHERE last_used < ?", [date('Y-m-d H:i:s', time() - 45*86400)]);
  return $t;
}
function bearer(){
  $h = $_SERVER['HTTP_AUTHORIZATION'] ?? ($_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '');
  if($h === '' && function_exists('getallheaders')){
    foreach((array)getallheaders() as $k => $v){ if(strcasecmp($k, 'Authorization') === 0){ $h = $v; break; } }
  }
  if(stripos($h, 'Bearer ') === 0) return trim(substr($h, 7));
  $t = (string)($_GET['token'] ?? '');
  return preg_match('/^[a-f0-9]{32}$/', $t) ? $t : '';
}
function auth(){
  static $a = false;
  if($a !== false) return $a;
  $t = bearer();
  if($t === ''){
    global $in;
    $t = (is_array($in) && !empty($in['token'])) ? (string)$in['token'] : ($_GET['token'] ?? ($_POST['token'] ?? ''));
  }
  $a = null;
  if($t && strlen($t) === 32){
    $r = one("SELECT * FROM sop_tokens WHERE token=?", [$t]);
    if($r){
      q("UPDATE sop_tokens SET last_used=? WHERE token=?", [now(), $t]);
      $a = $r;
    }
  }
  return $a;
}
function fail($msg, $http = 400){
  http_response_code($http);
  echo json_encode(['ok' => false, 'error' => $msg], JSON_UNESCAPED_UNICODE);
  exit;
}
function ok($data = []){
  echo json_encode(['ok' => true] + $data, JSON_UNESCAPED_UNICODE);
  exit;
}
/* Роли — как в школе. Уровни доступа:
   'client'  — ученица;
   'manager' — своё расписание и созвоны: менеджер и владелец;
   'staff'   — ученицы и отметки по созвонам: менеджер, куратор, владелец;
   'admin'   — статистика и импорт: куратор и владелец;
   'owner'   — настройки, люди, Telegram: только владелец.
   Закрытый аккаунт внутрь не попадает, даже если токен ещё жив. */
function requireRole($role){
  $a = auth();
  $u = $a && $a['user_id'] ? userById($a['user_id']) : null;
  if(!$u || $u['status'] !== 'active')
    fail('Нужен вход. Обновите страницу и войдите заново.', 401);
  $ok = ($role === 'client'  && $u['role'] === 'client') ||
        ($role === 'manager' && in_array($u['role'], ['manager', 'owner'], true)) ||
        ($role === 'staff'   && in_array($u['role'], ['manager', 'admin', 'owner'], true)) ||
        ($role === 'admin'   && in_array($u['role'], ['admin', 'owner'], true)) ||
        ($role === 'owner'   && $u['role'] === 'owner');
  if(!$ok){
    if($role === 'owner') fail('Этот раздел доступен только владельцу.', 403);
    if($role === 'admin') fail('Этот раздел доступен куратору и владельцу.', 403);
    if($role === 'manager' && $u['role'] === 'admin')
      fail('У куратора нет своего расписания — созвоны ведут менеджеры.', 403);
    fail('Нужен вход. Обновите страницу и войдите заново.', 401);
  }
  return $u;
}

/* ── Люди и вход ────────────────────────────────────────────── */
function userByEmail($email){
  $e = mb_strtolower(trim((string)$email));
  return $e === '' ? null : one("SELECT * FROM sop_users WHERE LOWER(email)=?", [$e]);
}
function userById($id){ return one("SELECT * FROM sop_users WHERE id=?", [(int)$id]); }
/* Слоты и записи держат id менеджера — это тот же sop_users. */
function managerById($id){ return userById($id); }
/* Роли, которые человек может выбрать сам. Владельца назначаем руками
   (первый аккаунт становится владельцем автоматически). */
function ROLES(){
  return ['client' => 'ученица', 'manager' => 'менеджер онбординга', 'admin' => 'куратор'];
}

function authPack($u, $fresh = true){
  $pack = ['me' => [
    'id' => (int)$u['id'], 'name' => $u['name'], 'email' => $u['email'],
    'role' => $u['role'], 'link' => (string)$u['link'],
    'phone' => (string)$u['phone'], 'tg' => (string)$u['tg'],
    'pair' => (string)($u['pair_name'] ?? ''),
    'recKey' => (string)$u['tg_key'],
  ]];
  if($fresh) $pack['token'] = issueToken($u['role'], $u['id']);
  return $pack;
}

/* Ученице аккаунт открывается сразу — по почте/телефону/телеграму
   подклеиваем её к карточке из списка школы (или заводим новую).
   Менеджеру — после подтверждения владельцем. Самый первый аккаунт
   становится владельцем: иначе подтверждать первого было бы некому. */
function userRegister($email, $pass, $name, $role, $phone = '', $tg = ''){
  $email = mb_strtolower(trim((string)$email));
  $name  = trim((string)$name);
  if(!filter_var($email, FILTER_VALIDATE_EMAIL)) return ['err' => 'Проверьте адрес почты.'];
  if(mb_strlen($pass) < 6)  return ['err' => 'Пароль — минимум шесть символов.'];
  if(mb_strlen($name) < 2)  return ['err' => 'Впишите имя и фамилию.'];
  if(!isset(ROLES()[$role])) return ['err' => 'Выберите, кто вы.'];
  if(userByEmail($email))   return ['err' => 'На эту почту уже есть аккаунт. Войдите или восстановите пароль.'];
  $tel = phoneOf($phone);
  if(trim((string)$phone) !== '' && $tel === '') return ['err' => 'Не разобрали телефон. Формат: +7 999 123-45-67'];
  $tg = ltrim(mb_substr(trim((string)$tg), 0, 60), '@');

  $first  = !one("SELECT id FROM sop_users LIMIT 1");
  if($first) $role = 'owner';
  $status = ($first || $role === 'client') ? 'active' : 'pending';
  $clientId = null;
  if($role === 'client'){
    $c = clientTouch($name, $email, $tel, $tg);
    if($c) $clientId = (int)$c['id'];
  }
  q("INSERT INTO sop_users(email,pass,name,role,status,client_id,phone,tg,tg_key,created_at) VALUES(?,?,?,?,?,?,?,?,?,?)",
    [$email, password_hash($pass, PASSWORD_DEFAULT), $name, $role, $status, $clientId, $tel, $tg,
     bin2hex(random_bytes(6)), now()]);
  if($status === 'pending'){
    adminNotify("🙋 Заявка на доступ: " . $name . " — " . ROLES()[$role] . "\n" . $email .
                "\nПодтвердить: " . siteUrl());
    sendMail(cfg('alert_email'), 'Заявка на доступ — ' . $name,
      $name . ' просит доступ: ' . ROLES()[$role] . ".\nПочта: " . $email .
      "\n\nПодтвердить или отклонить: " . siteUrl());
  }
  return ['status' => $status, 'role' => $role];
}

function userLogin($email, $pass){
  $u = userByEmail($email);
  if(!$u || !password_verify((string)$pass, $u['pass'])) return ['err' => 'Не сходится почта или пароль.'];
  if($u['status'] === 'pending')  return ['err' => 'Доступ ещё не подтверждён. Мы напишем, как только владелец подтвердит.'];
  if($u['status'] === 'rejected') return ['err' => 'Доступ закрыт. Напишите владельцу, если это ошибка.'];
  q("UPDATE sop_users SET last_at=? WHERE id=?", [now(), $u['id']]);
  return ['user' => $u];
}

/* Ссылка на смену пароля живёт два часа: хватает дойти до почты,
   и мало, чтобы забытое письмо стало отмычкой. */
function passResetMake($email){
  $u = userByEmail($email);
  if(!$u) return '';
  $t = bin2hex(random_bytes(16));
  q("UPDATE sop_users SET reset_token=?, reset_at=? WHERE id=?", [$t, now(), $u['id']]);
  return $t;
}
function passResetUser($token){
  if(!preg_match('/^[a-f0-9]{32}$/', (string)$token)) return null;
  $u = one("SELECT * FROM sop_users WHERE reset_token=?", [$token]);
  if(!$u || !$u['reset_at'] || ts($u['reset_at']) < time() - 2*3600) return null;
  return $u;
}

/* Карточка ученицы под её аккаунтом. Если регистрация случилась раньше
   импорта, карточка могла появиться позже — ищем и подклеиваем. */
function clientForUser($u){
  if(!$u) return null;
  if($u['client_id']){
    $c = clientById($u['client_id']);
    if($c) return $c;
  }
  $c = clientTouch($u['name'], $u['email'], $u['phone'], $u['tg']);
  if($c) q("UPDATE sop_users SET client_id=? WHERE id=?", [$c['id'], $u['id']]);
  return $c;
}

/* ── Ученицы ───────────────────────────────────────────────── */
function clientById($id){ return one("SELECT * FROM sop_clients WHERE id=?", [(int)$id]); }
function clientByKkey($k){
  return preg_match('/^[a-f0-9]{8,16}$/', (string)$k) ? one("SELECT * FROM sop_clients WHERE kkey=?", [$k]) : null;
}
function CLIENT_STATUSES(){
  return ['new' => 'новая', 'work' => 'в работе', 'done' => 'онбординг завершён',
          'refund' => 'возврат', 'frozen' => 'заморозка / старт позже'];
}
/* Причины возврата — категории из таблицы заказчицы процесса */
function REFUND_REASONS(){
  return ['до взаимодействия', 'отказ банка', 'ОПО (период охлаждения)', 'после работы ОПВ', 'другое'];
}

/* Стадия сопровождения — как колонка в таблице: 10-дневный цикл с планом
   касаний 0-3-5-7-10. Старт отсчёта — первый проведённый созвон. */
function stageOf($c, $held){
  if($c['status'] === 'done')   return 'Завершено';
  if($c['status'] === 'refund') return '—';
  if($c['status'] === 'frozen') return 'Заморозка';
  if(empty($c['start_dt']))     return 'Ещё не начали';
  $days = (int)floor((time() - ts($c['start_dt'] . ' 00:00:00')) / 86400);
  $plan = [0, 3, 5, 7, 10];
  $target = max(1, (int)cfg('target_calls'));
  if($held >= $target) return 'Цикл пройден (10 дн.)';
  $expected = 0;
  foreach($plan as $d) if($d <= $days) $expected++;
  if($held < $expected) return 'Просрочено: Д' . $plan[min($held, count($plan) - 1)];
  if($days > 10) return 'Цикл пройден (10 дн.)';
  return 'В графике · день ' . max(0, $days);
}
/* Личная ссылка ученицы на страницу записи. Пароля у неё нет —
   как в записи к психологу, пускаем по ключу из письма. */
function clientUrl($c){ return siteUrl() . '?k=' . $c['kkey']; }

function histAdd($clientId, $bookingId, $kind, $txt){
  q("INSERT INTO sop_history(client_id,booking_id,at,kind,txt) VALUES(?,?,?,?,?)",
    [(int)$clientId, $bookingId ? (int)$bookingId : null, now(), $kind, mb_substr($txt, 0, 490)]);
}

/* Запись открытая, как у психологов: ученица вписывает почту и контакты,
   а мы ищем её в списке, приехавшем из школы. Ищем по всему, что она о себе
   сказала: у части карточек нет почты, у части — телефона. */
function naytiKlientku($email, $phone, $tg){
  $email = mb_strtolower(trim((string)$email));
  if($email !== ''){
    $c = one("SELECT * FROM sop_clients WHERE LOWER(email)=?", [$email]);
    if($c) return $c;
  }
  $tel = phoneOf($phone);
  if($tel !== ''){
    $c = one("SELECT * FROM sop_clients WHERE phone=?", [$tel]);
    if($c) return $c;
  }
  $tg = mb_strtolower(ltrim(trim((string)$tg), '@'));
  if($tg !== ''){
    $c = one("SELECT * FROM sop_clients WHERE LOWER(tg)=?", [$tg]);
    if($c) return $c;
  }
  return null;
}
/* Не нашли в списке — всё равно записываем: список школы пополняется с
   опозданием, а человек уже пришёл. Карточка заводится своя, при следующем
   импорте она подклеится к школьной по почте. */
function clientTouch($name, $email, $phone, $tg){
  $c = naytiKlientku($email, $phone, $tg);
  if($c){
    /* дописываем то, чего в карточке не было */
    $email = mb_strtolower(trim((string)$email));
    $tel = phoneOf($phone);
    $tg = ltrim(trim((string)$tg), '@');
    if($email !== '' && trim((string)$c['email']) === '') q("UPDATE sop_clients SET email=? WHERE id=?", [$email, $c['id']]);
    if($tel !== '' && trim((string)$c['phone']) === '')   q("UPDATE sop_clients SET phone=? WHERE id=?", [$tel, $c['id']]);
    if($tg !== '' && trim((string)$c['tg']) === '')       q("UPDATE sop_clients SET tg=? WHERE id=?", [$tg, $c['id']]);
    return clientById($c['id']);
  }
  $email = mb_strtolower(trim((string)$email));
  $ckey = 'sop:' . substr(md5($email . '|' . phoneOf($phone) . '|' . mb_strtolower($name)), 0, 24);
  q("INSERT IGNORE INTO sop_clients(ckey,name,email,phone,tg,contact,course,status,kkey,tg_key,created_at)
     VALUES(?,?,?,?,?,'','','new',?,?,?)",
    [$ckey, mb_substr(trim((string)$name), 0, 110), $email, phoneOf($phone),
     ltrim(mb_substr(trim((string)$tg), 0, 60), '@'), bin2hex(random_bytes(6)), bin2hex(random_bytes(6)), now()]);
  $c = one("SELECT * FROM sop_clients WHERE ckey=?", [$ckey]);
  if($c) histAdd($c['id'], null, 'self', 'Записалась сама, в списке школы пока не нашлась');
  return $c;
}

/* Какие курсы школы берём в онбординг. Настройка courses_allow — по курсу
   на строку; пустая — берём всех. */
function coursesAllow(){
  $out = [];
  foreach(preg_split('/\r?\n/', (string)cfg('courses_allow')) as $s){
    $s = trim($s);
    if($s !== '') $out[] = $s;
  }
  return $out;
}
function courseAllowed($course){
  $allow = coursesAllow();
  if(!$allow) return true;
  $c = mb_strtolower((string)$course);
  foreach($allow as $a){
    if($a !== '' && mb_strpos($c, mb_strtolower($a)) !== false) return true;
  }
  return false;
}

/* Импорт из школы: ТОЛЬКО чтение shk_clients. Сопоставляем по ckey —
   он у школы уникален. Новые добавляются со статусом «новая», у знакомых
   обновляются контакты; статус, отчёты и счётчики онбординга не трогаем. */
function importClients(){
  initTables(); settings();
  /* Граница по дате: в школе лежит весь накопленный список, и без неё
     каждый ночной импорт затягивает сюда всю историю целиком. Берём только
     тех, кто появился в школе начиная с указанного дня. */
  $from = trim((string)cfg('import_from'));
  $ok = preg_match('/^\d{4}-\d{2}-\d{2}$/', $from);
  try{
    $rows = $ok
      ? all("SELECT ckey, name, contact, email, course, courses, phone, tg FROM shk_clients
             WHERE created_at >= ?", [$from . ' 00:00:00'])
      : all("SELECT ckey, name, contact, email, course, courses, phone, tg FROM shk_clients");
  }catch(Throwable $e){
    throw new Exception('Не дотянулись до списка школы: ' . $e->getMessage());
  }
  $added = 0; $updated = 0; $skipped = 0;
  foreach($rows as $r){
    $ckey = trim((string)$r['ckey']);
    $name = trim((string)$r['name']);
    if($ckey === '' || mb_strlen($name) < 2){ $skipped++; continue; }
    /* Курсов у ученицы может быть несколько — берём полный список. */
    $course = trim((string)($r['courses'] ?? ''));
    if($course === '') $course = trim((string)($r['course'] ?? ''));
    if(!courseAllowed($course)){ $skipped++; continue; }
    $email = mb_strtolower(trim((string)($r['email'] ?? '')));
    $phone = phoneOf($r['phone'] ?? '');
    $tg    = ltrim(trim((string)($r['tg'] ?? '')), '@');
    $contact = trim((string)($r['contact'] ?? ''));

    $c = one("SELECT * FROM sop_clients WHERE ckey=?", [$ckey]);
    /* Записалась сама раньше, чем приехала из школы: подклеиваем её карточку
       к школьному ключу по почте, чтобы не плодить двойников. */
    if(!$c && $email !== ''){
      $c = one("SELECT * FROM sop_clients WHERE LOWER(email)=? AND ckey LIKE 'sop:%'", [$email]);
      if($c) q("UPDATE sop_clients SET ckey=? WHERE id=?", [$ckey, $c['id']]);
    }
    if($c){
      /* Пустое из школы не затирает то, что уже знаем: у старых карточек
         школы почты может не быть, а у нас она уже есть. */
      q("UPDATE sop_clients SET name=?, email=?, phone=?, tg=?, contact=?, course=? WHERE id=?",
        [$name,
         $email !== '' ? $email : $c['email'],
         $phone !== '' ? $phone : $c['phone'],
         $tg    !== '' ? $tg    : $c['tg'],
         $contact !== '' ? $contact : $c['contact'],
         $course !== '' ? mb_substr($course, 0, 290) : $c['course'],
         $c['id']]);
      $updated++;
    }else{
      q("INSERT INTO sop_clients(ckey,name,email,phone,tg,contact,course,status,kkey,tg_key,imported_at,created_at)
         VALUES(?,?,?,?,?,?,?,'new',?,?,?,?)",
        [$ckey, mb_substr($name, 0, 110), $email, $phone, $tg, mb_substr($contact, 0, 110),
         mb_substr($course, 0, 290), bin2hex(random_bytes(6)), bin2hex(random_bytes(6)), now(), now()]);
      histAdd((int)db()->lastInsertId(), null, 'import', 'Появилась в списке (импорт из школы)');
      $added++;
    }
  }
  setSetting('last_import', now());
  logit('import', "добавлено $added, обновлено $updated, пропущено $skipped");
  return ['added' => $added, 'updated' => $updated, 'skipped' => $skipped, 'total' => count($rows)];
}

/* ── Слоты и запись ─────────────────────────────────────────── */
function bookingById($id){ return one("SELECT * FROM sop_bookings WHERE id=?", [(int)$id]); }
function bookingByCode($code){
  $code = strtoupper(trim((string)$code));
  return preg_match('/^[A-Z0-9]{4,8}$/', $code) ? one("SELECT * FROM sop_bookings WHERE code=?", [$code]) : null;
}
/* Ученица видит и время, и к какому менеджеру записывается — выбирает
   слот сама, а не получает менеджера подобранным системой. */
function freeTimes($days = 30, $mgrKey = ''){
  /* $mgrKey — для персональной ссылки записи (Настройки менеджера → «Ваша
     ссылка для записи»): показывает только слоты этого менеджера. */
  if($mgrKey !== ''){
    return all("SELECT s.id, s.dt, s.manager_id, u.name manager_name FROM sop_slots s
                JOIN sop_users u ON u.id = s.manager_id
                WHERE s.booking_id IS NULL AND u.status='active' AND u.tg_key=?
                  AND s.dt > ? AND s.dt < ?
                ORDER BY s.dt, u.name",
               [$mgrKey, now(), date('Y-m-d H:i:s', time() + $days*86400)]);
  }
  return all("SELECT s.id, s.dt, s.manager_id, u.name manager_name FROM sop_slots s
              JOIN sop_users u ON u.id = s.manager_id
              WHERE s.booking_id IS NULL AND u.status='active'
                AND s.dt > ? AND s.dt < ?
              ORDER BY s.dt, u.name",
             [now(), date('Y-m-d H:i:s', time() + $days*86400)]);
}
/* Менеджер по своему tg_key (для персональной ссылки записи) */
function managerByRecKey($key){
  $key = trim((string)$key);
  if($key === '') return null;
  return one("SELECT * FROM sop_users WHERE tg_key=? AND role IN ('manager','owner') AND status='active'", [$key]);
}
function upcomingBooking($clientId){
  return one("SELECT b.*, u.name manager_name FROM sop_bookings b
              JOIN sop_users u ON u.id = b.manager_id
              WHERE b.client_id=? AND b.status='booked' AND b.dt > ?
              ORDER BY b.dt LIMIT 1", [(int)$clientId, now()]);
}
function slotLinkOf($slot){
  /* Ссылка на созвон: одноразовая из слота, а если её нет — постоянная менеджера. */
  if(trim((string)$slot['link']) !== '') return $slot['link'];
  $m = managerById($slot['manager_id']);
  return (string)($m['link'] ?? '');
}

/* Запись атомарна: слот забирается одним UPDATE с условием «ещё свободен» —
   две ученицы, нажавшие одновременно, не окажутся в одном времени. */
function bookSlot($slot, $client){
  $upd = q("UPDATE sop_slots SET booking_id=0 WHERE id=? AND booking_id IS NULL", [$slot['id']]);
  if($upd->rowCount() === 0) return null;
  $m = managerById($slot['manager_id']);
  q("INSERT INTO sop_bookings(code,client_id,manager_id,slot_id,dt,link,status,created_at)
     VALUES(?,?,?,?,?,?,'booked',?)",
    [newCode(), $client['id'], $slot['manager_id'], $slot['id'], $slot['dt'], slotLinkOf($slot), now()]);
  $bid = (int)db()->lastInsertId();
  q("UPDATE sop_slots SET booking_id=? WHERE id=?", [$bid, $slot['id']]);
  $b = bookingById($bid);
  histAdd($client['id'], $bid, 'booked', 'Записалась на созвон ' . fmtRu($b['dt']) . ' к ' . ($m['name'] ?? '—'));
  /* ученица переходит из «новых» в работу с первой же записью */
  if($client['status'] === 'new') q("UPDATE sop_clients SET status='work' WHERE id=?", [$client['id']]);
  notifyNewBooking($b, clientById($client['id']), $m);
  return $b;
}
/* Перенос — как в записи к психологу: старое место держим за ученицей,
   пока новое не забронировано. Сначала забираем новый слот, и только
   после этого освобождаем прежний. Слот, а не просто время: ученица
   видит и выбирает менеджера сама. */
function moveBooking($b, $slotId, $who){
  if($b['status'] !== 'booked') return 'inactive';
  $slot = one("SELECT * FROM sop_slots WHERE id=?", [(int)$slotId]);
  if(!$slot) return 'taken';
  if((int)$slot['manager_id'] === (int)$b['manager_id'] && $slot['dt'] === $b['dt']) return 'same';
  $upd = q("UPDATE sop_slots SET booking_id=? WHERE id=? AND booking_id IS NULL", [$b['id'], $slot['id']]);
  if($upd->rowCount() === 0) return 'taken';
  $oldDt = $b['dt']; $oldSlot = $b['slot_id']; $oldMan = (int)$b['manager_id'];
  q("UPDATE sop_bookings SET slot_id=?, manager_id=?, dt=?, link=? WHERE id=?",
    [$slot['id'], $slot['manager_id'], $slot['dt'], slotLinkOf($slot), $b['id']]);
  q("UPDATE sop_slots SET booking_id=NULL WHERE id=?", [$oldSlot]);
  q("UPDATE sop_clients SET moved_cnt=moved_cnt+1 WHERE id=?", [$b['client_id']]);
  /* напоминания по старому времени не должны глушить новые */
  q("DELETE FROM sop_sent WHERE ref_id=? AND kind IN ('r24','r2','m2','ask','rep')", [$b['id']]);
  $c = clientById($b['client_id']);
  $mNew = managerById($slot['manager_id']);
  histAdd($b['client_id'], $b['id'], 'moved',
    'Перенос созвона: ' . fmtRu($oldDt) . ' → ' . fmtRu($slot['dt']) . ' — ' . $who);
  $b2 = bookingById($b['id']);
  if($c) tell($c['tg_chat'], $c['email'], 'Созвон перенесён — ' . fmtRu($b2['dt']),
    "↩️ Созвон перенесён\n\n🗓 Новое время: " . fmtRu($b2['dt']) .
    "\n👤 Менеджер: " . ($mNew['name'] ?? '—') .
    "\n🔗 Ссылка: " . ($b2['link'] ?: 'пришлём до встречи') .
    "\n🔑 Код записи прежний: " . $b2['code']);
  if($oldMan !== (int)$slot['manager_id']){
    $mOld = managerById($oldMan);
    if($mOld) tell($mOld['tg_chat'], $mOld['email'], 'Созвон перенесён к другому менеджеру',
      "↩️ Перенос: " . ($c['name'] ?? '—') . ' ушла с ' . fmtRu($oldDt) . " к другому менеджеру.\nВаш слот снова свободен.");
    if($mNew) tell($mNew['tg_chat'], $mNew['email'], 'Новая запись — ' . fmtRu($b2['dt']),
      "🔔 К вам перенесён созвон\n" . fmtRu($b2['dt']) . "\nУченица: " . ($c['name'] ?? '—'));
  }else{
    if($mNew) tell($mNew['tg_chat'], $mNew['email'], 'Созвон перенесён',
      "↩️ Перенос: " . ($c['name'] ?? '—') . "\n" . fmtRu($oldDt) . ' → ' . fmtRu($b2['dt']));
  }
  return $b2;
}

function cancelBooking($b, $who){
  if($b['status'] !== 'booked') return false;
  q("UPDATE sop_bookings SET status='cancelled' WHERE id=?", [$b['id']]);
  q("UPDATE sop_slots SET booking_id=NULL WHERE id=?", [$b['slot_id']]);
  $c = clientById($b['client_id']);
  histAdd($b['client_id'], $b['id'], 'cancelled', 'Отмена созвона ' . fmtRu($b['dt']) . ' — ' . $who);
  $m = managerById($b['manager_id']);
  if($m) tell($m['tg_chat'], $m['email'], 'Созвон отменён',
    "❌ Отмена: " . ($c['name'] ?? '—') . ', ' . fmtRu($b['dt']) . "\nСлот снова свободен.");
  return true;
}

/* Созвон состоялся. Отчёт менеджер может дописать сразу или позже —
   без отчёта запись висит в «ждут отчёта». */
function markHeld($b, $who){
  if(!in_array($b['status'], ['booked', 'noshow'], true)) return false;
  q("UPDATE sop_bookings SET status='held' WHERE id=?", [$b['id']]);
  histAdd($b['client_id'], $b['id'], 'held', 'Созвон ' . fmtRu($b['dt']) . ' проведён — ' . $who);
  $c = clientById($b['client_id']);
  if($c && $c['status'] === 'new') q("UPDATE sop_clients SET status='work' WHERE id=?", [$b['client_id']]);
  /* первый проведённый созвон открывает 10-дневный цикл */
  if($c && empty($c['start_dt']))
    q("UPDATE sop_clients SET start_dt=? WHERE id=?", [date('Y-m-d', ts($b['dt'])), $c['id']]);
  return true;
}
function markNoshow($b, $who){
  if(!in_array($b['status'], ['booked', 'held'], true)) return false;
  q("UPDATE sop_bookings SET status='noshow' WHERE id=?", [$b['id']]);
  q("UPDATE sop_slots SET booking_id=NULL WHERE id=?", [$b['slot_id']]);
  q("UPDATE sop_clients SET noshow_cnt=noshow_cnt+1 WHERE id=?", [$b['client_id']]);
  $c = clientById($b['client_id']);
  histAdd($b['client_id'], $b['id'], 'noshow', 'Не пришла на созвон ' . fmtRu($b['dt']) . ' — отметил(а) ' . $who);
  adminNotify("🚫 Неявка: " . ($c['name'] ?? '—') . ", " . fmtRu($b['dt']) .
              "\nНеявок у неё: " . ((int)($c['noshow_cnt'] ?? 0) + 1) .
              "\nСтоит связаться и предложить новое время.");
  if($c) tell($c['tg_chat'], $c['email'], 'Мы вас потеряли на созвоне',
    "Здравствуйте, " . $c['name'] . "!\nМы ждали вас на созвоне " . fmtRu($b['dt']) . ", но не встретились.\n" .
    "Ничего страшного — выберите новое удобное время: " . clientUrl($c));
  return true;
}
function saveReport($b, $note, $status, $next, $nextDt, $who, $pub = ''){
  $pub = mb_substr(trim((string)$pub), 0, 2000);
  q("UPDATE sop_bookings SET rep_note=?, rep_status=?, rep_next=?, rep_next_dt=?, rep_pub=?, rep_at=? WHERE id=?",
    [mb_substr((string)$note, 0, 4000), mb_substr((string)$status, 0, 190),
     mb_substr((string)$next, 0, 390), $nextDt ?: null, $pub, now(), $b['id']]);
  if(!one("SELECT id FROM sop_history WHERE booking_id=? AND kind='report'", [$b['id']]))
    histAdd($b['client_id'], $b['id'], 'report', 'Отчёт по созвону ' . fmtRu($b['dt']) . ' — ' . $who);
  /* Итоги для ученицы уходят ей сразу — в кабинет она может и не заглянуть.
     Шлём один раз, чтобы правка отчёта не сыпала повторами. */
  if($pub !== '' && !wasSent($b['id'], 'pub')){
    $c = clientById($b['client_id']);
    if($c) tell($c['tg_chat'], $c['email'], 'Итоги созвона — ' . fmtRu($b['dt']),
      "Здравствуйте, " . $c['name'] . "!\n\nИтоги созвона " . fmtRu($b['dt']) . " от менеджера:\n\n" . $pub .
      "\n\nЭти итоги сохранены и в вашем кабинете: " . clientUrl($c));
    markSent($b['id'], 'pub');
  }
  return true;
}

/* ── Очередь сообщений (реле забирает и шлёт в Telegram) ────── */
function send($chat, $text, $kb = null){
  if(!$chat) return false;
  q("INSERT INTO sop_outbox(chat,text,kb,status,created_at) VALUES(?,?,?, 'pending', ?)",
    [$chat, $text, $kb ? json_encode($kb, JSON_UNESCAPED_UNICODE) : null, now()]);
  return true;
}
/* Владельцы, привязавшие бота, получают служебные уведомления. */
function adminNotify($text){
  foreach(all("SELECT chat FROM sop_tg WHERE role='admin'") as $r) send($r['chat'], $text);
}
function markSent($refId, $kind, $via = ''){
  q("INSERT IGNORE INTO sop_sent(ref_id,kind,at,via) VALUES(?,?,?,?)", [$refId, $kind, now(), $via]);
}
function wasSent($refId, $kind){
  return (bool)one("SELECT kind FROM sop_sent WHERE ref_id=? AND kind=?", [$refId, $kind]);
}
function sendMail($to, $subject, $body){
  $to = trim((string)$to);
  if($to === '' || !filter_var($to, FILTER_VALIDATE_EMAIL)) return false;
  $host = $_SERVER['HTTP_HOST'] ?? 'booking.example.com';
  $from = 'soprovod@' . preg_replace('/[^a-z0-9.\-]/i', '', $host);
  $h = "From: " . cfg('org') . " <$from>\r\n"
     . "Content-Type: text/plain; charset=utf-8\r\n"
     . "X-Mailer: soprovod\r\n";
  $okSent = @mail($to, '=?UTF-8?B?' . base64_encode($subject) . '?=', $body, $h);
  logit('mail', ($okSent ? 'ok ' : 'fail ') . $to . ' — ' . $subject);
  return $okSent;
}
/* Реле живо, если выходило на связь последние 15 минут. Если нет
   (компьютер уснул) — срочное лучше отправить почтой, чем ждать. */
function tgAlive(){
  $t = cfg('last_relay');
  return $t !== '' && ts($t) > time() - 900;
}
/* Одно сообщение — в Telegram, если подключён и доставка работает,
   иначе на почту. Дублировать не надо: два одинаковых напоминания раздражают. */
function tell($chat, $email, $subject, $text){
  if($chat && tgAlive()){ send($chat, $text); return 'tg'; }
  if($email && sendMail($email, $subject, $text)) return 'mail';
  if($chat){ send($chat, $text); return 'tg'; }   /* больше нечем — пусть ждёт в очереди */
  return '';
}
function botLink($payload){
  $u = cfg('bot_user');
  return $u ? "https://t.me/$u?start=$payload" : '';
}
/* Страница записи по коду: там и ссылка на встречу, и перенос, и отмена. */
function codeUrl($b){ return siteUrl() . '?code=' . $b['code']; }

function notifyNewBooking($b, $c, $m){
  $forClient = "✅ Вы записаны на созвон\n\n🗓 " . fmtRu($b['dt']) .
    "\n👤 Менеджер: " . ($m['name'] ?? '—') .
    "\n🔗 Ссылка: " . ($b['link'] ?: 'пришлём до встречи') .
    "\n🔑 Код записи: " . $b['code'] .
    "\n\nНапомним за сутки и за два часа. Если время не подходит — " .
    "перенесите или отмените по коду: " . codeUrl($b);
  tell($c['tg_chat'], $c['email'], 'Вы записаны на созвон — ' . fmtRu($b['dt']), $forClient);
  if($m) tell($m['tg_chat'], $m['email'], 'Новая запись — ' . fmtRu($b['dt']),
    "🔔 К вам записалась ученица\n" . fmtRu($b['dt']) . "\n\n" .
    "Ученица: " . $c['name'] . "\n" .
    ($c['course'] !== '' ? "Курс: " . $c['course'] . "\n" : '') .
    ($c['phone'] !== ''  ? "Телефон: " . $c['phone'] . "\n" : '') .
    ($c['tg'] !== ''     ? "Telegram: @" . $c['tg'] . "\n" : '') .
    "Ссылка на встречу: " . ($b['link'] ?: 'пока нет — впишите свою в профиле или в слоте'));
  adminNotify("🔔 Запись: " . $c['name'] . " → " . ($m['name'] ?? '—') . ", " . fmtRu($b['dt']));
}

/* ── Телеграм: обработка входящих (логика на сервере) ───────── */
function tgBind($chat, $role, $userId = null, $clientId = null){
  q("INSERT INTO sop_tg(chat,role,user_id,client_id,state,updated_at) VALUES(?,?,?,?,'',?)
     ON DUPLICATE KEY UPDATE role=VALUES(role), user_id=VALUES(user_id),
       client_id=VALUES(client_id), updated_at=VALUES(updated_at)",
    [$chat, $role, $userId, $clientId, now()]);
}
function kbRow(...$btns){ return $btns; }
function ikb($rows){ return ['inline_keyboard' => $rows]; }
function btn($text, $data){ return ['text' => $text, 'callback_data' => $data]; }
function rememberWho($chat, $from){
  if(!$chat || !is_array($from)) return;
  $un = mb_substr((string)($from['username'] ?? ''), 0, 60);
  $fn = trim(mb_substr((string)($from['first_name'] ?? ''), 0, 60) . ' ' . mb_substr((string)($from['last_name'] ?? ''), 0, 60));
  q("UPDATE sop_tg SET uname=?, fname=? WHERE chat=?", [$un, $fn, $chat]);
}

function handleUpdate($u){
  initTables();

  /* --- нажатие кнопки --- */
  if(isset($u['callback_query'])){
    $cq   = $u['callback_query'];
    $chat = $cq['message']['chat']['id'] ?? 0;
    if(!$chat) return;
    rememberWho($chat, $cq['from'] ?? null);
    $p = explode(':', (string)($cq['data'] ?? ''));

    /* ученица: отмена и перенос своей записи */
    if($p[0] === 'cancel' && isset($p[1])){
      $b = bookingById((int)$p[1]);
      if($b && $b['status'] === 'booked'){
        send($chat, "Точно отменить запись на " . fmtRu($b['dt']) . "?", ikb([
          kbRow(btn('Да, отменить', 'cancelyes:' . $b['id']), btn('Нет, оставить', 'cancelno:' . $b['id'])),
        ]));
      }
      return;
    }
    if($p[0] === 'cancelyes' && isset($p[1])){
      $b = bookingById((int)$p[1]);
      $t = $b ? one("SELECT * FROM sop_tg WHERE chat=?", [$chat]) : null;
      if($b && $t && (int)$t['client_id'] === (int)$b['client_id'] && cancelBooking($b, 'ученица (Telegram)')){
        $c = clientById($b['client_id']);
        send($chat, "Запись отменена. Когда будете готовы — выберите новое время: " . clientUrl($c));
      }
      return;
    }
    if($p[0] === 'cancelno'){ send($chat, "Хорошо, запись остаётся в силе."); return; }
    if($p[0] === 'move' && isset($p[1])){
      $b = bookingById((int)$p[1]);
      $t = $b ? one("SELECT * FROM sop_tg WHERE chat=?", [$chat]) : null;
      if($b && $t && (int)$t['client_id'] === (int)$b['client_id'] && $b['status'] === 'booked'){
        /* место остаётся за ней, пока не выберет новое — как в записи к психологу */
        send($chat, "Выберите новое время здесь — старое место держим за вами, пока не выберете:\n" . codeUrl($b));
      }
      return;
    }

    /* менеджер: исход созвона после встречи */
    if($p[0] === 'held' && isset($p[1], $p[2])){
      $b = bookingById((int)$p[1]);
      $t = $b ? one("SELECT * FROM sop_tg WHERE chat=?", [$chat]) : null;
      $sam = $t && $t['user_id'] ? userById($t['user_id']) : null;
      if(!$b || !$sam) return;
      if($p[2] === 'h' && markHeld($b, $sam['name'])){
        send($chat, "Записали: созвон состоялся. Не забудьте отчёт — две минуты в кабинете:\n" . siteUrl());
      }
      if($p[2] === 'n' && markNoshow($b, $sam['name'])){
        send($chat, "Записали неявку. Ученице ушло приглашение выбрать новое время.");
      }
      return;
    }
    return;
  }

  /* --- обычное сообщение --- */
  $msg  = $u['message'] ?? null;
  if(!$msg) return;
  $chat = $msg['chat']['id'] ?? 0;
  $text = trim((string)($msg['text'] ?? ''));
  if(!$chat) return;

  if(mb_strpos($text, '/start') === 0){
    $payload = trim(mb_substr($text, 6));
    /* m_КЛЮЧ — менеджер или владелец, c_КЛЮЧ — ученица */
    if(preg_match('/^m_([a-f0-9]{8,16})$/', $payload, $mm)){
      $sam = one("SELECT * FROM sop_users WHERE tg_key=?", [$mm[1]]);
      if($sam && $sam['status'] === 'active' && in_array($sam['role'], ['manager', 'admin', 'owner'], true)){
        q("UPDATE sop_users SET tg_chat=? WHERE id=?", [$chat, $sam['id']]);
        /* куратор и владелец получают и служебные уведомления */
        tgBind($chat, $sam['role'] === 'manager' ? 'manager' : 'admin', $sam['id']);
        rememberWho($chat, $msg['from'] ?? null);
        send($chat, "Готово, " . $sam['name'] . "! Сюда будут приходить " .
          ($sam['role'] === 'manager' ? "записи, напоминания и вопросы после созвонов."
                                      : "служебные уведомления: записи, неявки, «зависшие» ученицы."));
        return;
      }
    }
    if(preg_match('/^c_([a-f0-9]{8,16})$/', $payload, $mm)){
      $c = one("SELECT * FROM sop_clients WHERE tg_key=?", [$mm[1]]);
      if($c){
        q("UPDATE sop_clients SET tg_chat=? WHERE id=?", [$chat, $c['id']]);
        tgBind($chat, 'client', null, $c['id']);
        rememberWho($chat, $msg['from'] ?? null);
        $b = upcomingBooking($c['id']);
        send($chat, "Здравствуйте, " . $c['name'] . "! Напоминания о созвонах теперь будут приходить сюда." .
          ($b ? "\n\nБлижайший созвон: " . fmtRu($b['dt']) : "\n\nВыбрать время созвона: " . clientUrl($c)));
        return;
      }
    }
    send($chat, "Здравствуйте! Это бот сопровождения. Чтобы подключиться, перейдите по личной ссылке из письма или из кабинета.");
    return;
  }
  /* На свободный текст не отвечаем умно — честно говорим, куда писать. */
  send($chat, "Я умею только напоминать и принимать нажатия кнопок. Если нужен человек — ответьте на письмо или напишите менеджеру.");
}

/* ── Планировщик: напоминания и ежедневная забота ───────────── */
function tick(){
  initTables();
  settings();
  $dur  = (int)cfg('duration');
  $nowT = time();
  $rows = all("SELECT * FROM sop_bookings WHERE status IN ('booked','held')
                 AND dt > ? AND dt < ?",
              [date('Y-m-d H:i:s', $nowT - 3*86400), date('Y-m-d H:i:s', $nowT + 8*86400)]);
  foreach($rows as $b){
    $t = ts($b['dt']);
    $c = clientById($b['client_id']);
    $m = managerById($b['manager_id']);
    if(!$c || !$m) continue;
    $end = $t + $dur * 60;

    /* за сутки — ученице */
    if(cfg('remind24') === '1' && $b['status'] === 'booked'
       && $nowT >= $t - 24*3600 && $nowT < $t && !wasSent($b['id'], 'r24')){
      $txt = "⏰ Завтра созвон с менеджером\n" . fmtRu($b['dt']) . "\nМенеджер: " . $m['name'] .
        "\nСсылка: " . ($b['link'] ?: 'пришлём отдельно') .
        "\n\nЕсли не сможете — перенесите, чтобы время досталось другой: " . clientUrl($c);
      markSent($b['id'], 'r24', tell($c['tg_chat'], $c['email'], 'Завтра созвон — ' . fmtRu($b['dt']), $txt) ?: 'none');
    }
    /* за два часа — ученице */
    if(cfg('remind2') === '1' && $b['status'] === 'booked'
       && $nowT >= $t - 2*3600 && $nowT < $t && !wasSent($b['id'], 'r2')){
      $txt = "⏰ Через 2 часа — созвон\n" . fmtRu($b['dt']) .
        "\nСсылка: " . ($b['link'] ?: 'уточняется') . "\n\nНайдите тихое место и наушники.";
      markSent($b['id'], 'r2', tell($c['tg_chat'], $c['email'], 'Через 2 часа созвон', $txt) ?: 'none');
    }
    /* за два часа — менеджеру */
    if($b['status'] === 'booked' && ($m['tg_chat'] || $m['email'])
       && $nowT >= $t - 2*3600 && $nowT < $t && !wasSent($b['id'], 'm2')){
      $txt = "⏰ Через 2 часа созвон\n" . fmtRu($b['dt']) . "\nУченица: " . $c['name'] .
        ($c['course'] !== '' ? "\nКурс: " . $c['course'] : '') .
        ($c['phone'] !== ''  ? "\nТелефон: " . $c['phone'] : '') .
        ($c['tg'] !== ''     ? "\nTelegram: @" . $c['tg'] : '') .
        "\nСсылка: " . ($b['link'] ?: '—');
      markSent($b['id'], 'm2', tell($m['tg_chat'], $m['email'], 'Через 2 часа созвон', $txt) ?: 'none');
    }
    /* после созвона — менеджеру вопрос об исходе */
    if($b['status'] === 'booked' && $m['tg_chat'] && $nowT >= $end + 5*60 && !wasSent($b['id'], 'ask')){
      send($m['tg_chat'], "Созвон " . fmtRu($b['dt']) . " с " . $c['name'] . " — как прошёл?", ikb([
        kbRow(btn('Провели', 'held:' . $b['id'] . ':h')),
        kbRow(btn('Ученица не пришла', 'held:' . $b['id'] . ':n')),
      ]));
      markSent($b['id'], 'ask');
    }
    /* отчёт не сдан через 3 часа после созвона */
    if($b['status'] === 'held' && !$b['rep_at'] && $nowT >= $end + 3*3600 && !wasSent($b['id'], 'rep')){
      tell($m['tg_chat'], $m['email'], 'Нет отчёта по созвону',
        "📝 Отчёт по созвону " . fmtRu($b['dt']) . " (" . $c['name'] . ") ещё не написан.\n" .
        "Две минуты в кабинете — и анамнез ученицы останется полным: " . siteUrl());
      markSent($b['id'], 'rep');
    }
  }
  dailyCare();
  checkQueueStall();
  setSetting('last_tick', now());
}

/* Раз в сутки: подтянуть новых учениц из школы и позвать «зависших» —
   тех, кто появился давно, а на созвон так и не записался. */
function dailyCare(){
  $today = date('Y-m-d');
  if(cfg('last_daily') === $today) return;
  setSetting('last_daily', $today);

  if(cfg('auto_import') === '1'){
    try{ importClients(); }catch(Throwable $e){ logit('import', 'авто: ' . $e->getMessage()); }
  }

  $days = max(1, (int)cfg('stale_days'));
  $edge = date('Y-m-d H:i:s', time() - $days*86400);
  $stale = all("SELECT c.* FROM sop_clients c
                WHERE c.status='new' AND c.refused=0 AND c.created_at < ?
                  AND NOT EXISTS (SELECT 1 FROM sop_bookings b
                                  WHERE b.client_id=c.id AND b.status='booked' AND b.dt > ?)",
               [$edge, now()]);
  $names = [];
  foreach($stale as $c){
    if(wasSent($c['id'], 'stale')) continue;
    /* ученице — мягкое приглашение, один раз */
    tell($c['tg_chat'], $c['email'], 'Выберите время созвона с менеджером',
      "Здравствуйте, " . $c['name'] . "!\n\nМы хотим помочь вам войти в профессию — для этого " .
      "менеджер сопровождения созвонится с вами и всё разложит по полочкам.\n" .
      "Выберите удобное время здесь: " . clientUrl($c));
    markSent($c['id'], 'stale');
    $names[] = $c['name'];
  }
  if($names){
    adminNotify("⏳ Без записи дольше $days дн.: " . count($names) . "\n" . implode("\n", array_slice($names, 0, 15)) .
      (count($names) > 15 ? "\n…" : ''));
  }

  /* Дайджест куратору: сводка за вчера, по понедельникам — ещё и за неделю.
     Заказчица процесса просила видеть картину дня, не заходя в кабинет. */
  adminDigest('вчера', date('Y-m-d 00:00:00', strtotime('-1 day')), date('Y-m-d 00:00:00'));
  if(date('N') === '1')
    adminDigest('за неделю', date('Y-m-d 00:00:00', strtotime('-7 day')), date('Y-m-d 00:00:00'));
}

function adminDigest($label, $f, $t){
  $novyh   = (int)one("SELECT COUNT(*) c FROM sop_clients  WHERE created_at >= ? AND created_at < ?", [$f, $t])['c'];
  $zapisey = (int)one("SELECT COUNT(*) c FROM sop_bookings WHERE created_at >= ? AND created_at < ?", [$f, $t])['c'];
  $proved  = (int)one("SELECT COUNT(*) c FROM sop_bookings WHERE status='held'   AND dt >= ? AND dt < ?", [$f, $t])['c'];
  $neyavok = (int)one("SELECT COUNT(*) c FROM sop_bookings WHERE status='noshow' AND dt >= ? AND dt < ?", [$f, $t])['c'];
  $zaversh = (int)one("SELECT COUNT(*) c FROM sop_clients  WHERE done_at >= ? AND done_at < ?", [$f, $t])['c'];
  $vperedi  = (int)one("SELECT COUNT(*) c FROM sop_bookings WHERE status='booked' AND dt > NOW()", [])['c'];
  if(!$novyh && !$zapisey && !$proved && !$neyavok && !$zaversh) return;  /* тишина — не шумим */
  adminNotify("📊 Сводка $label\n" .
    "Новых учениц: $novyh\nЗаписей на созвон: $zapisey\nПроведено: $proved\nНеявок: $neyavok\n" .
    "Онбординг завершили: $zaversh\nЗаписей впереди: $vperedi");
}

/* Тревога: очередь стоит, значит реле не работает. Telegram в этот
   момент как раз недоступен, поэтому зовём по почте. */
function checkQueueStall(){
  $old = one("SELECT id FROM sop_outbox WHERE status='pending' AND created_at < ? LIMIT 1",
             [date('Y-m-d H:i:s', time() - 3600)]);
  if(!$old) return;
  if(cfg('stall_mailed') !== '' && ts(cfg('stall_mailed')) > time() - 12*3600) return;
  setSetting('stall_mailed', now());
  sendMail(cfg('alert_email'), 'Реле сопровождения не работает',
    "Сообщения в Telegram копятся в очереди больше часа.\n" .
    "Скорее всего, компьютер с реле выключен или уснул.\n" .
    "Проверить: relay/install-relay-launchd.sh --kak-dela");
}

/* Планировщик едет попутно с запросами, если крон ещё не заведён;
   чаще раза в три минуты не дёргаем. */
function tickMaybe(){
  $t = cfg('last_tick');
  if($t !== '' && ts($t) > time() - 180) return;
  try{ tick(); }catch(Throwable $e){ logit('tick', $e->getMessage()); }
}
