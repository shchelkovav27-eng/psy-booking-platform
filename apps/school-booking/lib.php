<?php
/* ═══════════════════════════════════════════════════════════════
   Агрегатор записи на консультации — общее ядро.
   Данные: MySQL (таблицы shk_*), та же база, что у «Трекера».
   Секреты БД берём из ../config.php — отдельно ничего не заводим.
   ═══════════════════════════════════════════════════════════════ */

if (!defined('PSY_LIB')) { define('PSY_LIB', 1); }

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
  db()->exec("CREATE TABLE IF NOT EXISTS shk_settings(
    k VARCHAR(40) PRIMARY KEY, v TEXT) $e");
  db()->exec("CREATE TABLE IF NOT EXISTS shk_experts(
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(120) NOT NULL,
    pin VARCHAR(20) NOT NULL,
    link VARCHAR(300) DEFAULT '',
    direction VARCHAR(120) DEFAULT '',
    active TINYINT DEFAULT 1,
    tg_key VARCHAR(16) NOT NULL,
    tg_chat BIGINT NULL,
    created_at DATETIME NOT NULL) $e");
  db()->exec("CREATE TABLE IF NOT EXISTS shk_slots(
    id INT AUTO_INCREMENT PRIMARY KEY,
    expert_id INT NOT NULL,
    dt DATETIME NOT NULL,
    booking_id INT NULL,
    created_at DATETIME NOT NULL,
    UNIQUE KEY uniq_slot (expert_id, dt),
    KEY k_dt (dt)) $e");
  db()->exec("CREATE TABLE IF NOT EXISTS shk_bookings(
    id INT AUTO_INCREMENT PRIMARY KEY,
    code VARCHAR(8) NOT NULL UNIQUE,
    expert_id INT NOT NULL,
    slot_id INT NOT NULL,
    dt DATETIME NOT NULL,
    client_name VARCHAR(120) NOT NULL,
    contact VARCHAR(120) NOT NULL,
    category VARCHAR(120) DEFAULT '',
    note VARCHAR(400) DEFAULT '',
    link VARCHAR(300) DEFAULT '',
    status VARCHAR(12) DEFAULT 'booked',
    tg_chat BIGINT NULL,
    csat TINYINT NULL, fit VARCHAR(20) NULL, nps TINYINT NULL,
    comment VARCHAR(1000) NULL, csat_at DATETIME NULL,
    rep_req VARCHAR(400) NULL, rep_plan VARCHAR(400) NULL, rep_next VARCHAR(400) NULL,
    rep_at DATETIME NULL,
    paid TINYINT DEFAULT 0, paid_sum INT DEFAULT 0,
    created_at DATETIME NOT NULL,
    KEY k_dt (dt), KEY k_exp (expert_id)) $e");
  db()->exec("CREATE TABLE IF NOT EXISTS shk_tokens(
    token CHAR(32) PRIMARY KEY,
    role VARCHAR(10) NOT NULL,
    expert_id INT NULL,
    created_at DATETIME NOT NULL,
    last_used DATETIME NOT NULL) $e");
  db()->exec("CREATE TABLE IF NOT EXISTS shk_login(
    ip VARCHAR(45) PRIMARY KEY, fails INT DEFAULT 0, blocked_until DATETIME NULL) $e");
  db()->exec("CREATE TABLE IF NOT EXISTS shk_outbox(
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
  db()->exec("CREATE TABLE IF NOT EXISTS shk_sent(
    booking_id INT NOT NULL, kind VARCHAR(20) NOT NULL, at DATETIME NOT NULL,
    via VARCHAR(10) DEFAULT '',
    PRIMARY KEY (booking_id, kind)) $e");
  db()->exec("CREATE TABLE IF NOT EXISTS shk_tg(
    chat BIGINT PRIMARY KEY,
    role VARCHAR(10) NOT NULL,
    expert_id INT NULL,
    booking_id INT NULL,
    state VARCHAR(30) DEFAULT '',
    updated_at DATETIME NOT NULL) $e");
  db()->exec("CREATE TABLE IF NOT EXISTS shk_history(
    id INT AUTO_INCREMENT PRIMARY KEY,
    booking_id INT NOT NULL,
    at DATETIME NOT NULL,
    kind VARCHAR(20) NOT NULL,
    txt VARCHAR(400) NOT NULL,
    KEY k_b (booking_id), KEY k_at (at)) $e");
  db()->exec("CREATE TABLE IF NOT EXISTS shk_wait(
    id INT AUTO_INCREMENT PRIMARY KEY,
    code VARCHAR(8) NOT NULL UNIQUE,
    name VARCHAR(120) NOT NULL,
    contact VARCHAR(120) NOT NULL,
    email VARCHAR(120) DEFAULT '',
    topic VARCHAR(120) DEFAULT '',
    pair TINYINT DEFAULT 0,
    note VARCHAR(400) DEFAULT '',
    tg_chat BIGINT NULL,
    status VARCHAR(12) DEFAULT 'waiting',
    created_at DATETIME NOT NULL,
    notified_at DATETIME NULL,
    closed_at DATETIME NULL,
    KEY k_status (status)) $e");
  db()->exec("CREATE TABLE IF NOT EXISTS shk_links(
    id INT AUTO_INCREMENT PRIMARY KEY,
    expert_id INT NOT NULL,
    url VARCHAR(300) NOT NULL,
    booking_id INT NULL,
    used_at DATETIME NULL,
    created_at DATETIME NOT NULL,
    UNIQUE KEY uniq_link (expert_id, url),
    KEY k_free (expert_id, booking_id)) $e");
  db()->exec("CREATE TABLE IF NOT EXISTS shk_clients(
    id INT AUTO_INCREMENT PRIMARY KEY,
    ckey VARCHAR(120) NOT NULL UNIQUE,
    name VARCHAR(120) NOT NULL,
    contact VARCHAR(120) DEFAULT '',
    email VARCHAR(120) DEFAULT '',
    course VARCHAR(120) DEFAULT '',
    allowed INT DEFAULT 0,
    note VARCHAR(400) DEFAULT '',
    blocked TINYINT DEFAULT 0,
    blocked_why VARCHAR(200) DEFAULT '',
    created_at DATETIME NOT NULL) $e");
  db()->exec("CREATE TABLE IF NOT EXISTS shk_users(
    id INT AUTO_INCREMENT PRIMARY KEY,
    email VARCHAR(120) NOT NULL UNIQUE,
    pass VARCHAR(255) NOT NULL,
    name VARCHAR(120) NOT NULL,
    role VARCHAR(10) NOT NULL,
    status VARCHAR(10) NOT NULL DEFAULT 'active',
    expert_id INT NULL,
    ckey VARCHAR(120) DEFAULT '',
    reset_token CHAR(32) NULL,
    reset_at DATETIME NULL,
    created_at DATETIME NOT NULL,
    last_at DATETIME NULL,
    KEY k_status (status, role)) $e");
  db()->exec("CREATE TABLE IF NOT EXISTS shk_access(
    token CHAR(32) PRIMARY KEY,
    ckey VARCHAR(120) NOT NULL,
    email VARCHAR(120) NOT NULL,
    created_at DATETIME NOT NULL,
    used_at DATETIME NULL,
    KEY k_ckey (ckey)) $e");
  db()->exec("CREATE TABLE IF NOT EXISTS shk_refusals(
    id INT AUTO_INCREMENT PRIMARY KEY,
    at DATETIME NOT NULL,
    why VARCHAR(20) NOT NULL,
    dt DATETIME NULL,
    name VARCHAR(120) DEFAULT '',
    contact VARCHAR(120) DEFAULT '',
    course VARCHAR(120) DEFAULT '',
    detail VARCHAR(200) DEFAULT '',
    KEY k_at (at), KEY k_why (why)) $e");
  db()->exec("CREATE TABLE IF NOT EXISTS shk_nodupe(
    k1 VARCHAR(120) NOT NULL, k2 VARCHAR(120) NOT NULL,
    who VARCHAR(60) DEFAULT '', at DATETIME NOT NULL,
    PRIMARY KEY (k1,k2)) $e");
  db()->exec("CREATE TABLE IF NOT EXISTS shk_log(
    id INT AUTO_INCREMENT PRIMARY KEY, at DATETIME NOT NULL,
    kind VARCHAR(20), txt VARCHAR(500)) $e");
}

/* Доп. колонки появились позже — добавляем на лету, чтобы не ронять базу */
function migrate(){
  static $done; if($done) return; $done = 1;
  $have = [];
  foreach(all("SHOW COLUMNS FROM shk_experts") as $c) $have[$c['Field']] = 1;
  $add = [
    'slug'    => "VARCHAR(60) NULL",
    'price'   => "INT DEFAULT 0",
    'tg_user' => "VARCHAR(60) DEFAULT ''",
    'email'   => "VARCHAR(120) DEFAULT ''",
    'phone'   => "VARCHAR(60) DEFAULT ''",
    'on_site' => "TINYINT DEFAULT 1",
  ];
  foreach($add as $col => $def){
    if(!isset($have[$col])) db()->exec("ALTER TABLE shk_experts ADD COLUMN `$col` $def");
  }
  if(!isset($have['slug'])) db()->exec("ALTER TABLE shk_experts ADD UNIQUE KEY uniq_slug (slug)");
  $haveB = [];
  foreach(all("SHOW COLUMNS FROM shk_bookings") as $c) $haveB[$c['Field']] = 1;
  if(!isset($haveB['direction'])) db()->exec("ALTER TABLE shk_bookings ADD COLUMN direction VARCHAR(60) DEFAULT ''");
  if(!isset($haveB['zoom_id']))   db()->exec("ALTER TABLE shk_bookings ADD COLUMN zoom_id VARCHAR(30) DEFAULT ''");
  if(!isset($haveB['email']))     db()->exec("ALTER TABLE shk_bookings ADD COLUMN email VARCHAR(120) DEFAULT ''");
  foreach(['utm_source','utm_medium','utm_campaign','utm_term'] as $u)
    if(!isset($haveB[$u]))        db()->exec("ALTER TABLE shk_bookings ADD COLUMN `$u` VARCHAR(120) DEFAULT ''");
  if(!isset($haveB['hook_status']))db()->exec("ALTER TABLE shk_bookings ADD COLUMN hook_status VARCHAR(10) DEFAULT ''");
  if(!isset($haveB['hook_tries'])) db()->exec("ALTER TABLE shk_bookings ADD COLUMN hook_tries INT DEFAULT 0");
  if(!isset($haveB['pair']))       db()->exec("ALTER TABLE shk_bookings ADD COLUMN pair TINYINT DEFAULT 0");
  if(!isset($haveB['phone']))      db()->exec("ALTER TABLE shk_bookings ADD COLUMN phone VARCHAR(20) DEFAULT ''");
  if(!isset($haveB['tg_nick']))    db()->exec("ALTER TABLE shk_bookings ADD COLUMN tg_nick VARCHAR(64) DEFAULT ''");
  if(!isset($haveB['confirm']))    db()->exec("ALTER TABLE shk_bookings ADD COLUMN confirm VARCHAR(10) DEFAULT ''");
  if(!isset($haveB['confirm_at'])) db()->exec("ALTER TABLE shk_bookings ADD COLUMN confirm_at DATETIME NULL");
  if(!isset($haveB['course']))     db()->exec("ALTER TABLE shk_bookings ADD COLUMN course VARCHAR(120) DEFAULT ''");
  if(!isset($haveB['adm_note'])) db()->exec("ALTER TABLE shk_bookings ADD COLUMN adm_note VARCHAR(400) DEFAULT ''");
  /* Блокировка ученицы появилась позже самой таблицы — добавляем на лету */
  $haveC = [];
  foreach(all("SHOW COLUMNS FROM shk_clients") as $c) $haveC[$c['Field']] = 1;
  if(!isset($haveC['blocked']))     db()->exec("ALTER TABLE shk_clients ADD COLUMN blocked TINYINT DEFAULT 0");
  if(!isset($haveC['blocked_why'])) db()->exec("ALTER TABLE shk_clients ADD COLUMN blocked_why VARCHAR(200) DEFAULT ''");
  /* Профиль ученицы: связь врозь, курсов может быть несколько, фото по желанию */
  if(!isset($haveC['phone']))   db()->exec("ALTER TABLE shk_clients ADD COLUMN phone VARCHAR(30) DEFAULT ''");
  if(!isset($haveC['tg']))      db()->exec("ALTER TABLE shk_clients ADD COLUMN tg VARCHAR(60) DEFAULT ''");
  if(!isset($haveC['photo']))   db()->exec("ALTER TABLE shk_clients ADD COLUMN photo VARCHAR(120) DEFAULT ''");
  if(!isset($haveC['courses'])) db()->exec("ALTER TABLE shk_clients ADD COLUMN courses VARCHAR(300) DEFAULT ''");
  if(!isset($haveC['about']))   db()->exec("ALTER TABLE shk_clients ADD COLUMN about VARCHAR(400) DEFAULT ''");
  /* Связь спрашиваем прямо при регистрации: по ней ищем старую карточку,
     а специалисту и куратору телефон нужен, чтобы до них дозвонились. */
  $haveU = [];
  foreach(all("SHOW COLUMNS FROM shk_users") as $c) $haveU[$c['Field']] = 1;
  if(!isset($haveU['phone'])) db()->exec("ALTER TABLE shk_users ADD COLUMN phone VARCHAR(30) DEFAULT ''");
  if(!isset($haveU['tg']))    db()->exec("ALTER TABLE shk_users ADD COLUMN tg VARCHAR(60) DEFAULT ''");
  /* Личный ключ бота у куратора: общий код на всех не позволял ни понять,
     кто подключился, ни отключить одного человека, не сломав остальным. */
  if(!isset($haveU['tg_key']))  db()->exec("ALTER TABLE shk_users ADD COLUMN tg_key VARCHAR(16) DEFAULT ''");
  if(!isset($haveU['tg_chat'])) db()->exec("ALTER TABLE shk_users ADD COLUMN tg_chat BIGINT NULL");
  $haveG = [];
  foreach(all("SHOW COLUMNS FROM shk_tg") as $c) $haveG[$c['Field']] = 1;
  if(!isset($haveG['user_id'])) db()->exec("ALTER TABLE shk_tg ADD COLUMN user_id INT NULL");
  /* Бот привязывался к отдельной записи: записалась второй раз — и
     напоминания снова шли мимо. Держим чат у самой ученицы. */
  if(!isset($haveC['tg_chat'])) db()->exec("ALTER TABLE shk_clients ADD COLUMN tg_chat BIGINT NULL");
  if(!isset($haveC['tg_key']))  db()->exec("ALTER TABLE shk_clients ADD COLUMN tg_key VARCHAR(16) DEFAULT ''");
  $haveT = [];
  foreach(all("SHOW COLUMNS FROM shk_tokens") as $c) $haveT[$c['Field']] = 1;
  if(!isset($haveT['user_id'])) db()->exec("ALTER TABLE shk_tokens ADD COLUMN user_id INT NULL");
  $haveU = [];
  foreach(all("SHOW COLUMNS FROM shk_users") as $c) $haveU[$c['Field']] = 1;
  if(!isset($haveU['nudged_at'])) db()->exec("ALTER TABLE shk_users ADD COLUMN nudged_at DATETIME NULL");
  if(!isset($haveB['ckey'])){
    db()->exec("ALTER TABLE shk_bookings ADD COLUMN ckey VARCHAR(120) DEFAULT ''");
    db()->exec("CREATE INDEX k_ckey ON shk_bookings (ckey)");
  }
  /* Старым записям ключ ученицы проставляем один раз: без него запас
     консультаций считался бы только по новым встречам. */
  if(cfg('ckey_filled') !== '1'){
    foreach(all("SELECT id, contact, email, client_name FROM shk_bookings WHERE ckey='' OR ckey IS NULL") as $r)
      q("UPDATE shk_bookings SET ckey=? WHERE id=?", [clientKey($r['contact'], $r['email'], $r['client_name']), $r['id']]);
    setSetting('ckey_filled', '1');
  }
  /* Карточки ученицам, которые записывались до появления запаса консультаций:
     иначе список у координатора был бы пуст, хотя встречи в системе есть. */
  if(cfg('clients_filled') !== '1'){
    foreach(all("SELECT ckey, MAX(id) mx FROM shk_bookings WHERE ckey<>'' GROUP BY ckey") as $r){
      if(one("SELECT id FROM shk_clients WHERE ckey=?", [$r['ckey']])) continue;
      $b = one("SELECT client_name, contact, email, course FROM shk_bookings WHERE id=?", [$r['mx']]);
      if(!$b) continue;
      q("INSERT IGNORE INTO shk_clients(ckey,name,contact,email,course,allowed,created_at)
         VALUES(?,?,?,?,?,0,?)",
        [$r['ckey'], $b['client_name'], $b['contact'], $b['email'], (string)$b['course'], now()]);
    }
    setSetting('clients_filled', '1');
  }
  $haveS = [];
  foreach(all("SHOW COLUMNS FROM shk_sent") as $c) $haveS[$c['Field']] = 1;
  if(!isset($haveS['via'])) db()->exec("ALTER TABLE shk_sent ADD COLUMN via VARCHAR(10) DEFAULT ''");
  $haveT = [];
  foreach(all("SHOW COLUMNS FROM shk_tg") as $c) $haveT[$c['Field']] = 1;
  if(!isset($haveT['uname'])) db()->exec("ALTER TABLE shk_tg ADD COLUMN uname VARCHAR(64) DEFAULT ''");
  if(!isset($haveT['fname'])) db()->exec("ALTER TABLE shk_tg ADD COLUMN fname VARCHAR(120) DEFAULT ''");
  if(!isset($have['topics']))     db()->exec("ALTER TABLE shk_experts ADD COLUMN topics TEXT NULL");
  if(!isset($have['surname']))    db()->exec("ALTER TABLE shk_experts ADD COLUMN surname VARCHAR(80) DEFAULT ''");
  if(!isset($have['pairs']))      db()->exec("ALTER TABLE shk_experts ADD COLUMN pairs TINYINT DEFAULT 0");
}

/* ── Фамилия из ФИО ──────────────────────────────────────────
   В таблице встречается и «Петрова Анна», и «Ольга Смирнова», поэтому
   фамилию определяем: сверяем слова с латинским expert_id, а если его нет —
   по окончанию. Нужно, чтобы список шёл по алфавиту фамилий. */
function translit($s){
  $map = ['а'=>'a','б'=>'b','в'=>'v','г'=>'g','д'=>'d','е'=>'e','ё'=>'e','ж'=>'zh','з'=>'z',
          'и'=>'i','й'=>'y','к'=>'k','л'=>'l','м'=>'m','н'=>'n','о'=>'o','п'=>'p','р'=>'r',
          'с'=>'s','т'=>'t','у'=>'u','ф'=>'f','х'=>'h','ц'=>'c','ч'=>'ch','ш'=>'sh','щ'=>'sch',
          'ъ'=>'','ы'=>'y','ь'=>'','э'=>'e','ю'=>'yu','я'=>'ya'];
  $s = mb_strtolower(trim($s));
  $out = '';
  for($i = 0; $i < mb_strlen($s); $i++){
    $c = mb_substr($s, $i, 1);
    $out .= $map[$c] ?? $c;
  }
  return $out;
}
function looksLikeSurname($w){
  $w = mb_strtolower($w);
  foreach(['ова','ева','ёва','ина','ына','ская','цкая','ная','нко','ук','юк','ян','ва','ко','ий','ых','ова'] as $suf){
    if(mb_substr($w, -mb_strlen($suf)) === $suf) return true;
  }
  return false;
}
function surnameOf($name, $slug = ''){
  $words = preg_split('/\s+/u', trim((string)$name), -1, PREG_SPLIT_NO_EMPTY);
  if(!$words) return '';
  if(count($words) === 1) return $words[0];
  $slug = mb_strtolower(trim((string)$slug));
  if($slug !== ''){
    $best = null; $bestD = PHP_INT_MAX;
    foreach($words as $w){
      $d = levenshtein(translit($w), $slug);
      if($d < $bestD){ $bestD = $d; $best = $w; }
    }
    if($best !== null && $bestD <= 3) return $best;
  }
  foreach($words as $w) if(looksLikeSurname($w)) return $w;
  return $words[0];
}
function sortKey($s){
  return str_replace('ё', 'е', mb_strtolower(trim((string)$s)));
}
function backfillSurnames(){
  foreach(all("SELECT id, name, slug, surname FROM shk_experts") as $e){
    $sn = surnameOf($e['name'], $e['slug'] ?? '');
    if($sn !== ($e['surname'] ?? '')) q("UPDATE shk_experts SET surname=? WHERE id=?", [$sn, $e['id']]);
  }
}
/* Сортировка по фамилии — кириллицу MySQL раскладывает не так, как нам нужно,
   поэтому упорядочиваем в PHP. */
function bySurname($rows){
  usort($rows, function($a, $b){
    $x = sortKey($a['surname'] !== '' ? $a['surname'] : $a['name']);
    $y = sortKey($b['surname'] !== '' ? $b['surname'] : $b['name']);
    if($x === $y) return strcmp(sortKey($a['name']), sortKey($b['name']));
    return $x < $y ? -1 : 1;
  });
  return $rows;
}

/* ── Темы запросов ───────────────────────────────────────────
   Специалистка отмечает, с чем работает; клиентка выбирает тему первой
   и видит время только тех, кто эту тему ведёт. */
function TOPICS(){
  return [
    'Отношения с партнёром',
    'Близость и сексуальность',
    'После родов, тело и восстановление',
    'Уверенность в себе',
    'Кризис, расставание, развод',
    'Семья и дети',
    'Интимная гимнастика',
    'Другое / пока не сформулировала',
  ];
}
function topicsOf($expertRow){
  $t = trim((string)($expertRow['topics'] ?? ''));
  if($t === '') return TOPICS();          /* не отметила ничего — значит, берётся за любую тему */
  $a = json_decode($t, true);
  if(!is_array($a) || !$a) return TOPICS();
  return array_values(array_intersect(TOPICS(), $a));
}
function takesPairs($expertRow){ return (int)($expertRow['pairs'] ?? 0) === 1; }
function worksWith($expertRow, $topic){
  if($topic === '') return true;
  return in_array($topic, topicsOf($expertRow), true);
}

/* ── Направления ────────────────────────────────────────────── */
function DIRECTIONS(){
  return ['Секс-коуч', 'Семейный специалист', 'Тренер интимной гимнастики'];
}
function normDirection($s){
  $s = trim((string)$s);
  if($s === '') return '';
  $s = preg_replace('/\s+/u', ' ', $s);
  $first = trim(explode(',', $s)[0]);
  $low = mb_strtolower($first);
  if(mb_strpos($low, 'секс') !== false)   return 'Секс-коуч';
  if(mb_strpos($low, 'семей') !== false)  return 'Семейный специалист';
  if(mb_strpos($low, 'гимнаст') !== false) return 'Тренер интимной гимнастики';
  return $first;
}

/* ── Импорт списка специалистов из Google-таблицы ────────────
   Таблица — источник правды по составу; коды доступа, ссылки на комнаты
   и привязка Telegram живут только здесь и импортом не затираются. */
function fetchCsv($url){
  $c = curl_init($url);
  curl_setopt_array($c, [
    CURLOPT_RETURNTRANSFER => 1, CURLOPT_FOLLOWLOCATION => 1,
    CURLOPT_TIMEOUT => 25, CURLOPT_CONNECTTIMEOUT => 10,
    CURLOPT_USERAGENT => 'shkola-zapis/1.0',
  ]);
  $body = curl_exec($c);
  $err  = curl_error($c);
  $code = curl_getinfo($c, CURLINFO_HTTP_CODE);
  curl_close($c);
  if($body === false) throw new Exception('Не дотянулись до таблицы: ' . $err);
  if($code !== 200)   throw new Exception('Таблица ответила кодом ' . $code . '. Проверьте, что доступ открыт «всем, у кого есть ссылка».');
  return $body;
}
function parseCsv($body){
  $fh = fopen('php://temp', 'r+');
  fwrite($fh, $body);
  rewind($fh);
  $rows = [];
  $hdr  = fgetcsv($fh);
  if(!$hdr){ fclose($fh); return []; }
  $hdr = array_map(function($h){ return trim((string)$h, " \t\n\r\0\x0B\xEF\xBB\xBF"); }, $hdr);
  while(($r = fgetcsv($fh)) !== false){
    $row = [];
    foreach($hdr as $i => $h) $row[$h] = isset($r[$i]) ? trim((string)$r[$i]) : '';
    $rows[] = $row;
  }
  fclose($fh);
  return $rows;
}
function slugify($name, $fallback){
  $s = trim((string)$name);
  if($s !== '') return mb_strtolower(preg_replace('/[^a-zA-Z0-9_\-]/', '', $s));
  return 'x' . substr(md5($fallback), 0, 10);
}
function importSheet($url = null){
  initTables(); migrate();
  $url = $url ?: cfg('sheet_url');
  if($url === '') throw new Exception('Не задан адрес таблицы.');
  /* обычную ссылку на таблицу превращаем в ссылку на выгрузку CSV */
  if(preg_match('~/spreadsheets/d/([A-Za-z0-9_\-]+)~', $url, $m)){
    $gid = '0';
    if(preg_match('~[?&#]gid=(\d+)~', $url, $g)) $gid = $g[1];
    $url = 'https://docs.google.com/spreadsheets/d/' . $m[1] . '/export?format=csv&gid=' . $gid;
  }
  $rows = parseCsv(fetchCsv($url));
  if(!$rows) throw new Exception('В таблице не нашлось ни одной строки.');

  $added = 0; $updated = 0; $skipped = 0;
  foreach($rows as $r){
    $name = trim((string)($r['ФИО'] ?? ''));
    if(mb_strlen($name) < 3){ $skipped++; continue; }
    $slug = slugify($r['expert_id'] ?? '', $name);
    $dir  = normDirection(($r['Направление (сайт)'] ?? '') !== '' ? $r['Направление (сайт)'] : ($r['Направление (заявка)'] ?? ''));
    $price = (int)preg_replace('/\D/', '', (string)($r['Цена, ₽'] ?? ''));
    $tg    = ltrim(trim((string)($r['Telegram'] ?? '')), '@');
    $mail  = trim((string)($r['Email'] ?? ''));
    $phone = trim((string)($r['Телефон'] ?? ''));
    $site  = mb_strtolower(trim((string)($r['На сайте'] ?? ''))) === 'да' ? 1 : 0;

    $ex = one("SELECT * FROM shk_experts WHERE slug=?", [$slug]);
    if(!$ex) $ex = one("SELECT * FROM shk_experts WHERE name=? AND (slug IS NULL OR slug='')", [$name]);
    if($ex){
      q("UPDATE shk_experts SET name=?, slug=?, surname=?, direction=?, price=?, tg_user=?, email=?, phone=?, on_site=? WHERE id=?",
        [$name, $slug, surnameOf($name, $slug), $dir, $price, $tg, $mail, $phone, $site, $ex['id']]);
      $updated++;
    }else{
      /* кто попал в список — тот и работает; отбор идёт тем, кому координатор дал ссылку */
      q("INSERT INTO shk_experts(name, pin, link, direction, active, tg_key, created_at, slug, price, tg_user, email, phone, on_site)
         VALUES(?,?,'',?,1,?,?,?,?,?,?,?,?)",
        [$name, (string)random_int(1000, 9999), $dir, substr(bin2hex(random_bytes(8)), 0, 10), now(),
         $slug, $price, $tg, $mail, $phone, $site]);
      $added++;
    }
  }
  backfillSurnames();
  setSetting('sheet_url', $url);
  setSetting('sheet_at', now());
  logit('import', "добавлено $added, обновлено $updated, пропущено $skipped");
  return ['added' => $added, 'updated' => $updated, 'skipped' => $skipped, 'total' => count($rows)];
}

/* ── Настройки ──────────────────────────────────────────────── */
function defaults(){
  return [
    'admin_pin'   => '0000',   /* координаторы: всё, кроме настроек */
    'owner_pin'   => '0000',   /* владелец: плюс настройки и интеграции */
    'quota'       => '0',
    'ask_topic'   => '0',      /* спрашивать тему на первом шаге записи */
    'ask_pair'    => '0',      /* спрашивать, придёт одна или вдвоём */
    'cooldown'    => '0',      /* минимум дней между встречами одной ученицы; 0 — без правила */
    'nudge'       => '0',      /* звать ученицу, если давно не записывалась */
    'moderation'  => '0',      /* запись сначала заявка, куратор подтверждает */
    'email_req'   => '1',      /* почта обязательна при записи */
    'slot_norm'   => '50',      /* сколько встреч в месяц специалист должен провести; 0 — без нормы */
    'client_limit'=> '1',      /* считать ли ученице оставшиеся консультации */
    'courses'     => '',       /* строки «Курс = сколько консультаций положено» */
    'allowed_default' => '10', /* сколько положено, пока курс не указан */
    'experts_by'  => 'admin',  /* кто правит состав: owner | admin */
    'expert_mode' => 'staff',  /* contractor — подрядчик: оплата и отчёты; staff — штатный */
    'sticky'      => '1',      /* повторная запись — к тому же специалисту, что и в прошлый раз */
    'choose_expert' => '1',    /* ученица сама выбирает, к кому идти */
    'duration'    => '50',
    'tz'          => 'Europe/Moscow',
    'auto_link'   => 'none',      /* jitsi | none — если у эксперта нет своей комнаты */
    'tg_token'    => '',
    'bot_user'    => '',
    'relay_key'   => '',
    'admin_key'   => '',
    'remind24'    => '1',
    'remind2'     => '1',
    'remind15'    => '1',
    'ask_csat'    => '1',
    'org'         => 'Запись к специалисту',
    'alert_email' => '',
    'sms_on'      => '0',
    'sms_email'   => '',
    'sms_key'     => '',
    'sms_sign'    => 'SMS Aero',
    'rate'        => '1000',   /* сколько школа платит специалисту за проведённую консультацию */
    'hook_on'     => '0',
    'hook_url'    => '',
    'site_url'    => (defined('SITE_URL') ? SITE_URL : 'https://booking.example.com') . '/shkola/',
    'zoom_on'      => '0',
    'zoom_account' => '',
    'zoom_id'      => '',
    'zoom_secret'  => '',
    'zoom_token'   => '',
    'zoom_token_exp' => '0',
    'room_mode'   => 'own',      /* own — у каждой своя комната; shared — один общий кабинет */
    'shared_link' => '',
    'sheet_url'   => '',
    'sheet_at'    => '',
  ];
}
$PSY_S = null;
function settings(){
  global $PSY_S;
  if($PSY_S) return $PSY_S;
  initTables();
  migrate();
  $PSY_S = defaults();
  foreach(all("SELECT k,v FROM shk_settings") as $r) $PSY_S[$r['k']] = $r['v'];
  foreach(['relay_key','admin_key'] as $k){
    if($PSY_S[$k] === ''){ $PSY_S[$k] = bin2hex(random_bytes(12)); setSetting($k, $PSY_S[$k]); }
  }
  date_default_timezone_set($PSY_S['tz'] ?: 'Europe/Moscow');
  return $PSY_S;
}
/* Пишем и в базу, и в уже загруженный набор — иначе внутри одного запроса
   код после сохранения продолжал бы видеть старое значение. */
function setSetting($k, $v){
  global $PSY_S;
  q("INSERT INTO shk_settings(k,v) VALUES(?,?) ON DUPLICATE KEY UPDATE v=VALUES(v)", [$k, (string)$v]);
  if(is_array($PSY_S)) $PSY_S[$k] = (string)$v;
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
    if(!one("SELECT code FROM shk_bookings WHERE code=?", [$c])) return $c;
  }
  return strtoupper(substr(bin2hex(random_bytes(4)), 0, 6));
}
function logit($kind, $txt){
  try{ q("INSERT INTO shk_log(at,kind,txt) VALUES(?,?,?)", [now(), $kind, mb_substr($txt, 0, 480)]); }catch(Throwable $e){}
}
/* Только дата, без дня недели и часа: «15 сентября». Нужна там, где дата
   стоит после предлога и день недели ломал бы падеж («с вторник, 15…»). */
function fmtData($dt){
  $mon = [1=>'января',2=>'февраля',3=>'марта',4=>'апреля',5=>'мая',6=>'июня',7=>'июля',
          8=>'августа',9=>'сентября',10=>'октября',11=>'ноября',12=>'декабря'];
  $t = ts($dt);
  return (int)date('j', $t) . ' ' . $mon[(int)date('n', $t)];
}
/* «21 день», «22 дня», «25 дней» — иначе система говорит косноязычно */
function dney($n){
  $n = (int)$n; $o = $n % 10; $d = $n % 100;
  if($d >= 11 && $d <= 14) return $n . ' дней';
  if($o === 1) return $n . ' день';
  if($o >= 2 && $o <= 4) return $n . ' дня';
  return $n . ' дней';
}

function fmtRu($dt){
  $days  = ['Sunday'=>'воскресенье','Monday'=>'понедельник','Tuesday'=>'вторник','Wednesday'=>'среда',
            'Thursday'=>'четверг','Friday'=>'пятница','Saturday'=>'суббота'];
  $mon   = [1=>'января',2=>'февраля',3=>'марта',4=>'апреля',5=>'мая',6=>'июня',7=>'июля',
            8=>'августа',9=>'сентября',10=>'октября',11=>'ноября',12=>'декабря'];
  $t = ts($dt);
  return $days[date('l', $t)] . ', ' . (int)date('j', $t) . ' ' . $mon[(int)date('n', $t)] . ', ' . date('H:i', $t);
}

/* ── Авторизация ────────────────────────────────────────────── */
function clientIp(){ return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0'; }
function loginBlocked(){
  $r = one("SELECT * FROM shk_login WHERE ip=?", [clientIp()]);
  return $r && $r['blocked_until'] && ts($r['blocked_until']) > time();
}
function loginFail(){
  $ip = clientIp();
  $r  = one("SELECT * FROM shk_login WHERE ip=?", [$ip]);
  $f  = ($r ? (int)$r['fails'] : 0) + 1;
  $bu = $f >= 7 ? date('Y-m-d H:i:s', time() + 900) : null;
  q("INSERT INTO shk_login(ip,fails,blocked_until) VALUES(?,?,?)
     ON DUPLICATE KEY UPDATE fails=VALUES(fails), blocked_until=VALUES(blocked_until)", [$ip, $f, $bu]);
}
function loginOk(){ q("DELETE FROM shk_login WHERE ip=?", [clientIp()]); }

function issueToken($role, $expertId = null, $userId = null){
  $t = rnd(32);
  q("INSERT INTO shk_tokens(token,role,expert_id,user_id,created_at,last_used) VALUES(?,?,?,?,?,?)",
    [$t, $role, $expertId, $userId, now(), now()]);
  q("DELETE FROM shk_tokens WHERE last_used < ?", [date('Y-m-d H:i:s', time() - 45*86400)]);
  return $t;
}
function bearer(){
  $h = $_SERVER['HTTP_AUTHORIZATION'] ?? ($_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '');
  if($h === '' && function_exists('getallheaders')){
    foreach((array)getallheaders() as $k => $v){ if(strcasecmp($k, 'Authorization') === 0){ $h = $v; break; } }
  }
  if(stripos($h, 'Bearer ') === 0) return trim(substr($h, 7));
  /* Выгрузки открываются новой вкладкой, а туда заголовок не подставить —
     для них принимаем токен из адреса. */
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
    $r = one("SELECT * FROM shk_tokens WHERE token=?", [$t]);
    if($r){
      q("UPDATE shk_tokens SET last_used=? WHERE token=?", [now(), $t]);
      $a = $r;
    }
  }
  return $a;
}
function requireRole($role){
  $a = auth();
  /* Скрытый специалист и закрытый аккаунт внутрь не попадают. Раньше «Скрыть»
     убирало специалиста только из выбора ученицы, а кабинет и чужие истории
     оставались открыты — для ушедшего из школы это дыра. */
  if($a && !empty($a['expert_id'])){
    $e = expert($a['expert_id']);
    if(!$e || (int)$e['active'] !== 1)
      fail('Доступ закрыт. Если это ошибка — напишите куратору.', 403);
  }
  if($a && !empty($a['user_id'])){
    $u = userById($a['user_id']);
    if(!$u || $u['status'] !== 'active')
      fail('Доступ закрыт. Если это ошибка — напишите куратору.', 403);
  }
  $ok = $a && (
    ($role === 'admin'  && in_array($a['role'], ['admin', 'owner'], true)) ||
    ($role === 'owner'  && $a['role'] === 'owner') ||
    ($role === 'expert' && $a['role'] === 'expert')
  );
  if(!$ok){
    if($a && $role === 'owner') fail('Этот раздел доступен только владельцу — войдите своим кодом.', 403);
    fail('Нужен вход. Обновите страницу и войдите заново.', 401);
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

/* ── Эксперты и загрузка ────────────────────────────────────── */
function expert($id){ return one("SELECT * FROM shk_experts WHERE id=?", [(int)$id]); }
function loadOf($id){
  $r = one("SELECT COUNT(*) c FROM shk_bookings WHERE expert_id=? AND status<>'cancelled'", [(int)$id]);
  return (int)$r['c'];
}
/* Долг по отчётам: встречи, после которых прошли сутки, а отчёта нет.
   Такой специалист не получает новых клиентов, пока свободен кто-то ещё. */
/* Квота 0 — без ограничения. Она придумана для потока с конечным числом
   клиентов; там, где приём идёт постоянно, счётчик за всё время упирается
   в потолок и перестаёт что-либо значить. */
function quotaOn(){ return (int)cfg('quota') > 0; }

/* Штатный специалист школы вместо подрядчика: зарплата не сдельная, отчёт по
   каждой встрече не требуется. Остаётся исход — состоялась или не пришла —
   и пара строк для себя, если хочется. */
function staffMode(){ return cfg('expert_mode') === 'staff'; }

/* Состав специалистов правит владелец или, если так настроено, координатор:
   во внутреннем контуре школы двух специалистов заводит тот же человек,
   который каждый день с ними и работает. */
function expertsBy(){ return cfg('experts_by') === 'admin' ? 'admin' : 'owner'; }

/* ── Ученицы и их запас консультаций ──────────────────────────
   У каждой конечное число встреч по курсу, и его докидывают руками:
   вернулась из возврата, купила ещё — координатор добавляет. Считать
   это в голове невозможно, поэтому считает система. */
function limitOn(){ return cfg('client_limit') === '1'; }

/* Запись через подтверждение куратора. Ссылку на запись легко переслать
   подружке — без проверки в систему попадают чужие. Поэтому ученица
   оставляет заявку, слот за ней держится, а куратор подтверждает. */
function moderationOn(){ return cfg('moderation') === '1'; }

/* Журнал отказов. Ученица упёрлась — и до сих пор это нигде не оставалось
   следа: мы не знали ни сколько спроса теряем, ни по какой причине.
   А именно это отвечает на вопрос «сколько часов открывать». */
function REFUSALS(){
  return ['no_time' => 'нет свободного времени',
          'limit'   => 'консультации закончились',
          'cooldown'=> 'слишком рано после прошлой',
          'blocked' => 'запись закрыта куратором',
          'taken'   => 'время заняли раньше'];
}
/* Личный кабинет ученицы. Кода на каждую встречу мало: сходила пять раз —
   пять кодов, и ни один не показывает остальные. Пускаем по ссылке из
   письма: знать чужой адрес недостаточно, письмо придёт только владельцу. */
/* ── Люди и вход ─────────────────────────────────────────────
   Раньше вход был разный у каждой роли: ученица по коду записи,
   специалист просто выбирал себя из списка без пароля вовсе, куратор
   набирал общий пин. Теперь у всех одинаково — почта и пароль. */
/* Что отдаём приложению после входа: кто человек и куда ему можно.
   Роль в токене та же, что и раньше, поэтому все прежние разделы
   продолжают работать без единой правки. */
function authPack($u, $fresh = true){
  $role = $u['role'] === 'owner' ? 'owner' : $u['role'];
  $tokenRole = ($role === 'client') ? 'client' : $role;
  $pack = [
    'me' => [
      'id' => (int)$u['id'], 'name' => $u['name'], 'email' => $u['email'],
      'role' => $role, 'expertId' => $u['expert_id'] ? (int)$u['expert_id'] : null,
      'ckey' => $u['ckey'],
    ],
  ];
  if($fresh) $pack['token'] = issueToken($tokenRole, $u['expert_id'], $u['id']);
  return $pack;
}

function userByEmail($email){
  $e = mb_strtolower(trim((string)$email));
  return $e === '' ? null : one("SELECT * FROM shk_users WHERE LOWER(email)=?", [$e]);
}
function userById($id){ return one("SELECT * FROM shk_users WHERE id=?", [(int)$id]); }

/* Роли, которые человек может выбрать сам. Владельца назначаем руками. */
function ROLES(){
  return ['client' => 'ученица', 'expert' => 'специалист', 'admin' => 'куратор'];
}

/* Ученице аккаунт открывается сразу, специалисту и куратору — после
   подтверждения владельцем: это доступ к чужим персональным данным. */
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
  $tg  = ltrim(mb_substr(trim((string)$tg), 0, 60), '@');

  $status = $role === 'client' ? 'active' : 'pending';
  $ckey   = '';
  $nashli = null;
  if($role === 'client'){
    /* Ищем по всему, что она о себе сказала: у старых карточек почты нет,
       и без этого вся её история осталась бы в стороне. */
    $nashli = naytiUchenicu($email, $tel, $tg, $name);
    if($nashli){
      $ckey = $nashli['ckey'];
      /* Дописываем то, чего в карточке не было: теперь есть куда писать. */
      $c = clientFind($ckey);
      if($c){
        if(trim((string)$c['email']) === '') q("UPDATE shk_clients SET email=? WHERE id=?", [$email, $c['id']]);
        if($tel !== '' && trim((string)($c['phone'] ?? '')) === '') q("UPDATE shk_clients SET phone=? WHERE id=?", [$tel, $c['id']]);
        if($tg !== '' && trim((string)($c['tg'] ?? '')) === '')     q("UPDATE shk_clients SET tg=? WHERE id=?", [$tg, $c['id']]);
      }
      logit('client', 'Аккаунт ' . $email . ' подклеен к карточке по ' . $nashli['kak'] . ': ' . $name);
    }
  }
  q("INSERT INTO shk_users(email,pass,name,role,status,ckey,created_at) VALUES(?,?,?,?,?,?,?)",
    [$email, password_hash($pass, PASSWORD_DEFAULT), $name, $role, $status, $ckey, now()]);
  $uid = (int)db()->lastInsertId();
  if($tel !== '' || $tg !== '') q("UPDATE shk_users SET phone=?, tg=? WHERE id=?", [$tel, $tg, $uid]);
  /* Новая ученица: подтверждать её не нужно, но владелец должен видеть,
     что в школе появился человек — и сразу знать, узнала ли её система. */
  if($role === 'client'){
    adminNotify("👤 Новая ученица: " . $name . "\n" . $email .
      ($tel !== '' ? "\nТелефон: " . $tel : '') .
      ($tg !== ''  ? "\nTelegram: @" . $tg : '') .
      ($nashli
        ? "\n\n✅ Узнали её по " . $nashli['kak'] . " — прошлые встречи на месте."
        : "\n\n⚠️ В списках не нашли — карточка новая, история не подтянулась.") .
      (($nashli && $ckey !== '') ? '' : "\nЕсли она у вас в таблице — впишите ей почту в «Ученицах»."));
  }
  if($status === 'pending'){
    adminNotify("🙋 Заявка на доступ: " . $name . " — " . ROLES()[$role] . "\n" . $email .
                "\nПодтвердить: " . siteUrl());
    sendMail(cfg('alert_email'), 'Заявка на доступ — ' . $name,
      $name . ' просит доступ как ' . ROLES()[$role] . ".\nПочта: " . $email .
      "\n\nПодтвердить или отклонить: " . siteUrl());
  }
  return ['status' => $status, 'nashli' => $nashli,
          'vstrech' => $ckey !== '' ? clientUsed($ckey) : 0];
}

/* Возвращает пользователя, если пара сошлась и доступ открыт. */
function userLogin($email, $pass){
  $u = userByEmail($email);
  if(!$u || !password_verify((string)$pass, $u['pass'])) return ['err' => 'Не сходится почта или пароль.'];
  if($u['status'] === 'pending')  return ['err' => 'Доступ ещё не подтверждён. Мы напишем, как только владелец подтвердит.'];
  if($u['status'] === 'rejected') return ['err' => 'Доступ закрыт. Напишите куратору, если это ошибка.'];
  q("UPDATE shk_users SET last_at=? WHERE id=?", [now(), $u['id']]);
  return ['user' => $u];
}

/* Ссылка на смену пароля живёт два часа: этого хватает, чтобы дойти
   до почты, и мало, чтобы забытое письмо стало отмычкой. */
function passResetMake($email){
  $u = userByEmail($email);
  if(!$u) return '';
  $t = bin2hex(random_bytes(16));
  q("UPDATE shk_users SET reset_token=?, reset_at=? WHERE id=?", [$t, now(), $u['id']]);
  return $t;
}
function passResetUser($token){
  if(!preg_match('/^[a-f0-9]{32}$/', (string)$token)) return null;
  $u = one("SELECT * FROM shk_users WHERE reset_token=?", [$token]);
  if(!$u || !$u['reset_at'] || ts($u['reset_at']) < time() - 2*3600) return null;
  return $u;
}

/* Карточка ученицы под её же аккаунтом. Заводим, если человек
   зарегистрировался раньше, чем записался хоть раз. */
function clientForUser($u){
  if(!$u) return null;
  $ckey = (string)$u['ckey'];
  if($ckey !== ''){ $c = clientFind($ckey); if($c) return $c; }
  $c = one("SELECT * FROM shk_clients WHERE LOWER(email)=?", [mb_strtolower($u['email'])]);
  /* Аккаунт мог завестись раньше, чем появились телефон и телеграм —
     пробуем найти прежнюю карточку и по ним тоже. */
  if(!$c){
    $n = naytiUchenicu($u['email'], $u['phone'] ?? '', $u['tg'] ?? '', $u['name']);
    if($n) $c = clientFind($n['ckey']);
  }
  if(!$c){
    /* Телефон и телеграм из регистрации переезжают в карточку сразу —
       иначе профиль встречает ученицу пустыми полями, которые она уже заполняла */
    $ckey = clientKey('', $u['email'], $u['name']);
    q("INSERT IGNORE INTO shk_clients(ckey,name,contact,phone,tg,email,course,allowed,created_at)
       VALUES(?,?,?,?,?,?,?,0,?)",
      [$ckey, $u['name'], '', (string)($u['phone'] ?? ''), (string)($u['tg'] ?? ''), $u['email'], '', now()]);
    $c = clientFind($ckey);
  }
  if($c) q("UPDATE shk_users SET ckey=? WHERE id=?", [$c['ckey'], $u['id']]);
  return $c;
}

/* Курсы ученицы — списком: на школьных программах учатся по нескольку. */
function coursesOf($c){
  $raw = trim((string)($c['courses'] ?? ''));
  if($raw === '') $raw = trim((string)($c['course'] ?? ''));
  if($raw === '') return [];
  return array_values(array_filter(array_map('trim', explode(',', $raw))));
}

function accessMake($email){
  $email = mb_strtolower(trim((string)$email));
  if($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) return '';
  $c = one("SELECT * FROM shk_clients WHERE LOWER(email)=?", [$email]);
  if(!$c){
    $b = one("SELECT ckey FROM shk_bookings WHERE LOWER(email)=? AND ckey<>'' ORDER BY dt DESC LIMIT 1", [$email]);
    if(!$b) return '';
    $c = clientFind($b['ckey']);
    if(!$c) return '';
  }
  $t = bin2hex(random_bytes(16));
  q("INSERT INTO shk_access(token,ckey,email,created_at) VALUES(?,?,?,?)", [$t, $c['ckey'], $email, now()]);
  q("DELETE FROM shk_access WHERE ckey=? AND created_at < ?", [$c['ckey'], date('Y-m-d H:i:s', time() - 30*86400)]);
  return $t;
}
/* Ссылка живёт месяц: ученица заходит редко, а гонять её за новым письмом
   на каждую встречу — та же морока, что и с кодами. */
function accessCheck($token){
  if(!preg_match('/^[a-f0-9]{32}$/', (string)$token)) return null;
  $a = one("SELECT * FROM shk_access WHERE token=?", [$token]);
  if(!$a) return null;
  if(ts($a['created_at']) < time() - 30*86400) return null;
  q("UPDATE shk_access SET used_at=? WHERE token=?", [now(), $token]);
  return $a;
}

function logRefusal($why, $when = null, $name = '', $contact = '', $course = '', $detail = ''){
  try{
    q("INSERT INTO shk_refusals(at,why,dt,name,contact,course,detail) VALUES(?,?,?,?,?,?,?)",
      [now(), $why, $when, mb_substr($name,0,110), mb_substr($contact,0,110),
       mb_substr($course,0,110), mb_substr($detail,0,190)]);
  }catch(Throwable $e){ /* журнал не должен ронять запись */ }

  /* Куратору — сразу. Раньше отказ ложился в журнал, куда никто не смотрит:
     ученица упиралась в стену, писала «мне ничего не пришло», а узнавали
     об этом через день из переписки. Молча разворачивать людей нельзя. */
  try{
    $chelovek = ['limit'   => 'закончились открытые встречи',
                 'cooldown'=> 'не прошла пауза между встречами',
                 'blocked' => 'ей закрыта запись',
                 'taken'   => 'время заняли раньше неё',
                 'no_time' => 'не было свободного времени'];
    $prichina = $chelovek[$why] ?? $why;
    $chto = "🚫 Не смогла записаться: " . ($name ?: 'ученица') . "\n" .
            "Причина: " . $prichina . ($detail ? ' (' . $detail . ')' : '') . "\n" .
            ($when ? "Хотела на: " . fmtRu($when) . "\n" : '') .
            "Связь: " . ($contact ?: '—') .
            ($why === 'limit' ? "\n\nОткрыть ей встречи: «Ученицы» → «изменить»." : '') .
            ($why === 'cooldown' ? "\n\nЕсли нужно раньше — запишите её рукой." : '');
    adminNotify($chto);
    if(in_array($why, ['limit', 'blocked'], true) && cfg('alert_email') !== '')
      sendMail(cfg('alert_email'), 'Ученица не смогла записаться — ' . ($name ?: ''), $chto);
  }catch(Throwable $e){ /* оповещение тоже не должно ронять запись */ }
}

/* Пауза между встречами одной ученицы. Специалист — это сопровождение,
   а не служба поддержки: ходить каждый день смысла нет, и запас
   консультаций иначе сгорает за неделю. */
function cooldownDays(){ return max(0, (int)cfg('cooldown')); }
function cooldownConflict($ckey, $when){
  $d = cooldownDays();
  if($d <= 0 || $ckey === '') return null;
  $from = date('Y-m-d H:i:s', ts($when) - $d*86400);
  $to   = date('Y-m-d H:i:s', ts($when) + $d*86400);
  return one("SELECT dt FROM shk_bookings
               WHERE ckey=? AND status<>'cancelled' AND dt>=? AND dt<=? ORDER BY ABS(TIMESTAMPDIFF(SECOND, dt, ?)) LIMIT 1",
             [$ckey, $from, $to, $when]);
}

/* Курсы: строки вида «Сопровождение = 4». Число — сколько встреч положено. */
function COURSES(){
  static $c = null;
  if($c !== null) return $c;
  $c = [];
  foreach(preg_split('/\r?\n/', (string)cfg('courses')) as $line){
    $line = trim($line);
    if($line === '') continue;
    $parts = explode('=', $line);
    $name  = trim($parts[0]);
    if($name === '') continue;
    $c[$name] = isset($parts[1]) ? max(0, (int)trim($parts[1])) : 0;
  }
  return $c;
}
function courseSessions($course){
  $all = COURSES();
  return $all[$course] ?? 0;
}

/* Один человек — один ключ, как бы он ни оставил контакт.
   Телефон важнее ника: его меняют реже. */
function clientKey($contact, $email = '', $name = ''){
  $c = mb_strtolower(trim((string)$contact));
  $e = mb_strtolower(trim((string)$email));
  /* Прочерк — это не контакт. В старых таблицах связь часто не заполняли,
     и если считать её значением, все безконтактные ученицы склеятся в одну. */
  if(in_array($c, ['—', '-', '–', 'нет', 'н/д', '?'], true)) $c = '';
  $digits = preg_replace('/\D/', '', $c);
  if(strlen($digits) >= 10) return 'p:' . substr($digits, -10);
  if($c !== '' && $c[0] === '@') return 't:' . ltrim($c, '@');
  if(preg_match('~vk\.(?:com|ru)/([a-z0-9._]+)~u', $c, $m)) return 'v:' . $m[1];
  if(filter_var($c, FILTER_VALIDATE_EMAIL)) return 'e:' . $c;
  if($e !== '') return 'e:' . $e;
  $n = mb_strtolower(trim(preg_replace('/\s+/u', ' ', (string)$name)));
  if($n !== '') return 'n:' . $n;
  if($c !== '') return 'c:' . preg_replace('/\s+/u', ' ', $c);
  return 'x:' . bin2hex(random_bytes(6));   /* совсем ничего — отдельная карточка */
}

function clientFind($ckey){ return one("SELECT * FROM shk_clients WHERE ckey=?", [$ckey]); }

/* Имя для поиска задвоений: одна и та же женщина попадает дважды, если
   в одной записи контакт был, а в другой нет — ключи получаются разные,
   и запас с историей делятся пополам. */
function nameKey($name){
  $n = mb_strtolower(trim((string)$name));
  $n = preg_replace('/[^\p{L}\s]/u', ' ', $n);
  return preg_replace('/\s+/u', ' ', trim($n));
}

/* Тот же ключ, но без учёта порядка слов: «Светлана Новикова» и
   «Новикова Светлана» — один человек, а в старых таблицах порядок
   писали как придётся. */
function nameKeySort($name){
  $w = preg_split('/\s+/u', nameKey($name), -1, PREG_SPLIT_NO_EMPTY);
  if(!$w) return '';
  sort($w);
  return implode(' ', $w);
}

/* Найти карточку ученицы по любому признаку, какой есть.
   Почта у старых карточек почти нигде не заполнена — их заводили по
   телефону или телеграму, а то и по одному имени. Поэтому ищем по всему
   и заглядываем в записи: там связь бывает полнее, чем в карточке.
   Возвращаем ['ckey'=>…, 'kak'=>чем нашли] или null. */
function naytiUchenicu($email, $phone, $tg, $name){
  $email = mb_strtolower(trim((string)$email));
  $phone = phoneOf($phone);
  $tg    = ltrim(mb_strtolower(trim((string)$tg)), '@');

  if($email !== ''){
    $c = one("SELECT ckey FROM shk_clients WHERE LOWER(email)=?", [$email]);
    if($c) return ['ckey' => $c['ckey'], 'kak' => 'почте'];
    $b = one("SELECT ckey FROM shk_bookings WHERE LOWER(email)=? AND ckey<>'' ORDER BY dt DESC LIMIT 1", [$email]);
    if($b) return ['ckey' => $b['ckey'], 'kak' => 'почте'];
  }
  if($phone !== ''){
    $hvost = '%' . substr($phone, -10);
    $c = one("SELECT ckey FROM shk_clients WHERE phone<>'' AND phone LIKE ?", [$hvost]);
    if(!$c) $c = one("SELECT ckey FROM shk_clients WHERE contact LIKE ?", [$hvost]);
    if($c) return ['ckey' => $c['ckey'], 'kak' => 'телефону'];
    $b = one("SELECT ckey FROM shk_bookings WHERE ckey<>'' AND (phone LIKE ? OR contact LIKE ?) ORDER BY dt DESC LIMIT 1",
             [$hvost, $hvost]);
    if($b) return ['ckey' => $b['ckey'], 'kak' => 'телефону'];
  }
  if($tg !== ''){
    $c = one("SELECT ckey FROM shk_clients WHERE LOWER(tg)=? OR LOWER(contact)=?", [$tg, '@' . $tg]);
    if($c) return ['ckey' => $c['ckey'], 'kak' => 'телеграму'];
    $b = one("SELECT ckey FROM shk_bookings WHERE ckey<>'' AND (LOWER(tg_nick)=? OR LOWER(contact)=?) ORDER BY dt DESC LIMIT 1",
             [$tg, '@' . $tg]);
    if($b) return ['ckey' => $b['ckey'], 'kak' => 'телеграму'];
  }

  /* По имени — в последнюю очередь и только наверняка: если такое имя в
     базе одно и та карточка ещё ничья. Иначе рискуем отдать чужую историю
     однофамилице, а это хуже, чем завести новую карточку. */
  $nk = nameKeySort($name);
  if($nk !== ''){
    $svoi = [];
    foreach(all("SELECT ckey, name, email FROM shk_clients") as $c){
      if(nameKeySort($c['name']) === $nk) $svoi[] = $c;
    }
    if(count($svoi) === 1 && trim((string)$svoi[0]['email']) === '')
      return ['ckey' => $svoi[0]['ckey'], 'kak' => 'фамилии и имени'];
  }
  return null;
}

/* Склейка: всё переезжает на главную карточку, лишняя удаляется.
   Запас берём больший — уменьшить его случайно хуже, чем оставить лишнее. */
function clientMerge($mainId, $dupId){
  $m = one("SELECT * FROM shk_clients WHERE id=?", [(int)$mainId]);
  $d = one("SELECT * FROM shk_clients WHERE id=?", [(int)$dupId]);
  if(!$m || !$d || $m['id'] === $d['id']) return false;
  q("UPDATE shk_bookings SET ckey=? WHERE ckey=?", [$m['ckey'], $d['ckey']]);
  /* Аккаунт и телеграм-бот дубля переезжают на главную карточку —
     иначе после склейки человек «отвязался бы» от своей истории. */
  q("UPDATE shk_users SET ckey=? WHERE ckey=?", [$m['ckey'], $d['ckey']]);
  $note = trim(trim((string)$m['note']) . ' ' . trim((string)$d['note']));
  $beri = function($pole) use ($m, $d){ return trim((string)$m[$pole]) !== '' ? $m[$pole] : (string)($d[$pole] ?? ''); };
  q("UPDATE shk_clients SET contact=?, phone=?, tg=?, email=?, course=?, courses=?, about=?, allowed=?,
        note=?, blocked=?, blocked_why=?, tg_chat=COALESCE(tg_chat, ?) WHERE id=?",
    [ $beri('contact'), $beri('phone'), $beri('tg'), $beri('email'), $beri('course'),
      $beri('courses'), $beri('about'),
      max((int)$m['allowed'], (int)$d['allowed']),
      mb_substr($note, 0, 380),
      ((int)$m['blocked'] || (int)$d['blocked']) ? 1 : 0,
      $beri('blocked_why'), $d['tg_chat'] ?? null, $m['id'] ]);
  q("DELETE FROM shk_clients WHERE id=?", [$d['id']]);
  q("DELETE FROM shk_nodupe WHERE k1 IN (?,?) OR k2 IN (?,?)", [$d['ckey'],$d['ckey'],$d['ckey'],$d['ckey']]);
  logit('merge', 'Склеены карточки: ' . $d['name'] . ' → ' . $m['name']);
  return true;
}

/* «Фамилия Имя» для показа и сортировки. В старых таблицах писали кто как:
   «Иванова Мария» и «Мария Иванова» вперемешку. Узнаём имя по списку
   частых женских имён; если первое слово — имя, а второе нет, переставляем.
   Не уверены — оставляем как записано: лучше не тронуть, чем переврать. */
function IMENA(){
  static $m = null;
  if($m !== null) return $m;
  $spisok = 'анна мария ольга елена наталья наталия ирина светлана татьяна екатерина юлия анастасия '.
    'полина дарья алина карина марина валентина кристина виктория ксения александра елизавета вера '.
    'любовь надежда оксана регина диана алена алёна галина лариса людмила маргарита валерия вероника '.
    'софья софия евгения яна инна жанна лидия нина раиса тамара зоя альбина эльвира лилия камилла '.
    'аделина ангелина милана арина ульяна варвара таисия есения злата ева стефания амина сабина роза '.
    'луиза эмилия элина алла белла инга лада майя нелли элла юлиана олеся алиса кира влада злата '.
    'мирослава агата аглая пелагея серафима антонина клавдия зинаида изабелла эвелина каролина николь';
  $m = array_fill_keys(preg_split('/\s+/u', $spisok), 1);
  return $m;
}
function famFirst($name){
  $chist = trim(preg_replace('/\s+/u', ' ', (string)$name));
  $w = $chist === '' ? [] : explode(' ', $chist);
  if(count($w) !== 2) return $chist;
  $im = IMENA();
  $p1 = isset($im[mb_strtolower($w[0])]);
  $p2 = isset($im[mb_strtolower($w[1])]);
  if($p1 && !$p2) return $w[1] . ' ' . $w[0];   /* «Мария Иванова» → «Иванова Мария» */
  return $chist;
}
/* ключ сортировки: без регистра и с е вместо ё */
function fioKey($name){
  return str_replace('ё', 'е', mb_strtolower(famFirst($name)));
}

/* Сводка на сегодня и завтра. Психологу — про себя, куратору — про всех.
   Отвечает на вопрос, который задают каждое утро: что у меня сегодня,
   сколько уже прошло и к чему готовиться завтра.
   $expertId = 0 — по всей школе. */
function svodkaDni($expertId = 0){
  $gde  = $expertId ? ' AND b.expert_id=' . (int)$expertId : '';
  $den  = function($sdvig) use ($gde){
    $ot = date('Y-m-d 00:00:00', strtotime($sdvig . ' day'));
    $do = date('Y-m-d 23:59:59', strtotime($sdvig . ' day'));
    $rows = all("SELECT b.id, b.dt, b.client_name, b.status, b.link, b.course, b.confirm,
                        b.phone, b.tg_nick, b.contact, e.name ename
                   FROM shk_bookings b LEFT JOIN shk_experts e ON e.id=b.expert_id
                  WHERE b.dt>=? AND b.dt<=? AND b.status<>'cancelled'" . $gde . "
                  ORDER BY b.dt", [$ot, $do]);
    $out = [];
    foreach($rows as $r){
      $out[] = ['id' => (int)$r['id'], 'dt' => $r['dt'], 'name' => $r['client_name'],
                'status' => $r['status'], 'ename' => $r['ename'], 'course' => $r['course'],
                /* Отдаём саму ссылку, а не признак «есть»: в сводке по ней
                   переходят одним нажатием, без копирования через мессенджер. */
                'link' => (string)$r['link'], 'confirm' => $r['confirm'],
                'svyaz' => $r['phone'] ?: ($r['tg_nick'] ? '@' . $r['tg_nick'] : $r['contact'])];
    }
    return $out;
  };
  $segodnya = $den('+0');
  $proshlo  = 0; $vperedi = 0; $bezOtmetki = 0;
  $seychas  = time();
  foreach($segodnya as $b){
    if(ts($b['dt']) > $seychas){ $vperedi++; continue; }
    if($b['status'] === 'held' || $b['status'] === 'noshow') $proshlo++;
    else $bezOtmetki++;
  }
  return [
    'segodnya'   => $segodnya,
    'zavtra'     => $den('+1'),
    'proshlo'    => $proshlo,
    'vperedi'    => $vperedi,
    'bezOtmetki' => $bezOtmetki,
    /* Заявки, которые ещё не подтвердил куратор: ученица ждёт ответа */
    'zayavki'    => (int)one("SELECT COUNT(*) c FROM shk_bookings b WHERE b.status='pending'" . $gde)['c'],
  ];
}

/* Возможные дубли: пары карточек с причинами, почему мы думаем, что это
   один человек. Пары, про которые куратор уже сказал «разные», не выдаём. */
function dupePairs(){
  $vse = all("SELECT * FROM shk_clients");
  $ne  = [];
  foreach(all("SELECT k1,k2 FROM shk_nodupe") as $r) $ne[$r['k1'] . '|' . $r['k2']] = 1;
  $razlichny = function($a, $b) use ($ne){
    $k = [$a['ckey'], $b['ckey']]; sort($k);
    return isset($ne[$k[0] . '|' . $k[1]]);
  };
  $tel = function($c){
    $p = preg_replace('/\D/', '', (string)($c['phone'] ?: $c['contact']));
    return strlen($p) >= 10 ? substr($p, -10) : '';
  };
  $nik = function($c){
    $t = mb_strtolower(trim((string)$c['tg']));
    if($t === '' && strpos((string)$c['contact'], '@') === 0) $t = mb_strtolower(ltrim($c['contact'], '@'));
    return $t;
  };
  $pary = [];
  for($i = 0; $i < count($vse); $i++){
    for($j = $i + 1; $j < count($vse); $j++){
      $a = $vse[$i]; $b = $vse[$j];
      $prichiny = [];
      if(nameKeySort($a['name']) !== '' && nameKeySort($a['name']) === nameKeySort($b['name']))
        $prichiny[] = mb_strtolower($a['name']) === mb_strtolower($b['name']) ? 'одинаковое имя' : 'то же имя, слова переставлены';
      if($tel($a) !== '' && $tel($a) === $tel($b)) $prichiny[] = 'один телефон';
      if($nik($a) !== '' && $nik($a) === $nik($b)) $prichiny[] = 'один телеграм';
      if(trim((string)$a['email']) !== '' && mb_strtolower($a['email']) === mb_strtolower($b['email']))
        $prichiny[] = 'одна почта';
      if(!$prichiny || $razlichny($a, $b)) continue;
      $kart = function($c) use ($tel, $nik){
        return ['id' => (int)$c['id'], 'ckey' => $c['ckey'], 'name' => $c['name'],
                'phone' => $c['phone'] ?: '', 'tg' => $nik($c), 'email' => $c['email'],
                'contact' => $c['contact'], 'course' => $c['course'],
                'used' => clientUsed($c['ckey']), 'allowed' => clientAllowed($c),
                'tgBot' => !empty($c['tg_chat']), 'at' => substr((string)$c['created_at'], 0, 10)];
      };
      /* главной предлагаем ту, где больше истории; при равенстве — старшую */
      $ka = $kart($a); $kb = $kart($b);
      $glav = ($ka['used'] > $kb['used'] || ($ka['used'] === $kb['used'] && $ka['at'] <= $kb['at'])) ? 0 : 1;
      $pary[] = ['a' => $ka, 'b' => $kb, 'main' => $glav, 'why' => $prichiny];
      if(count($pary) >= 60) return $pary;
    }
  }
  return $pary;
}

/* Сколько уже занято. Отменённые не в счёт, неявка — в счёт: слот был занят. */
function clientUsed($ckey){
  $r = one("SELECT COUNT(*) c FROM shk_bookings WHERE ckey=? AND status<>'cancelled'", [$ckey]);
  return (int)$r['c'];
}
/* Сколько встреч положено. Порядок: что вписал куратор ученице лично →
   что задано по её курсу → общая стартовая норма. Без последней ступени
   ученица без курса получалась безлимитной, и весь счёт не работал. */
function clientAllowed($cl){
  if(!$cl) return 0;
  if((int)$cl['allowed'] > 0) return (int)$cl['allowed'];
  $poKursu = courseSessions($cl['course']);
  if($poKursu > 0) return $poKursu;
  return (int)cfg('allowed_default');
}
/* Заводим или обновляем карточку ученицы при записи. */
function clientTouch($name, $contact, $email, $course, $knownCkey = ''){
  /* Если ключ уже известен (залогиненная ученица), считать его заново из
     контакта нельзя: выйдет другой ключ, и мы заведём ей вторую карточку.
     Именно так у части учениц и появлялись дубли. */
  $ckey = $knownCkey !== '' ? $knownCkey : clientKey($contact, $email, $name);
  $cl = clientFind($ckey);
  if($cl){
    q("UPDATE shk_clients SET name=?, contact=?, email=?, course=IF(?='', course, ?) WHERE id=?",
      [$name, $contact, $email, $course, $course, $cl['id']]);
    return clientFind($ckey);
  }
  q("INSERT INTO shk_clients(ckey,name,contact,email,course,allowed,created_at) VALUES(?,?,?,?,?,?,?)",
    [$ckey, $name, $contact, $email, $course, courseSessions($course), now()]);
  return clientFind($ckey);
}

function reportDebt($id){
  if(staffMode()) return 0;          /* отчёты не обязательны — и придерживать клиентов не за что */
  $cut = date('Y-m-d H:i:s', time() - 24*3600 - ((int)cfg('duration')) * 60);
  /* Смотрим только свежие встречи: отчёт за встречу полугодовой давности
     уже никто не напишет, а вечный долг навсегда выключил бы дисциплину. */
  $from = date('Y-m-d H:i:s', time() - 30*24*3600);
  $r = one("SELECT COUNT(*) c FROM shk_bookings
             WHERE expert_id=? AND rep_at IS NULL AND status IN ('booked','held')
               AND dt < ? AND dt > ?", [(int)$id, $cut, $from]);
  return (int)$r['c'];
}
/* ── Один общий кабинет (например, единственный Zoom) ────────
   В этом режиме встречи не должны пересекаться по времени вообще,
   независимо от того, у кого из специалистов открыт слот. */
function sharedRoom(){
  /* Один Zoom-аккаунт физически не ведёт две встречи одновременно,
     поэтому при автосоздании конференций календарь всегда исключающий. */
  return cfg('room_mode') === 'shared' || zoomOn();
}
function busyInterval($when, $exceptBooking = null){
  $dur = (int)cfg('duration');
  $end = date('Y-m-d H:i:s', ts($when) + $dur * 60);
  $sql = "SELECT b.id FROM shk_bookings b
            JOIN shk_experts e ON e.id = b.expert_id
           WHERE b.status <> 'cancelled' AND (e.link IS NULL OR e.link = '')
             AND b.dt < ? AND DATE_ADD(b.dt, INTERVAL ? MINUTE) > ?";
  $args = [$end, $dur, $when];
  if($exceptBooking){ $sql .= " AND b.id <> ?"; $args[] = $exceptBooking; }
  return (bool)one($sql . " LIMIT 1", $args);
}

/* Общий кабинет: время считается занятым, как только его открыл кто-то другой,
   а не когда на него записались. Один Zoom — одна встреча в это время. */
function slotTakenByOther($when, $exceptExpert = null){
  /* у кого своя комната — тот в общий календарь не встаёт */
  $me = $exceptExpert ? expert($exceptExpert) : null;
  if($me && ownRoom($me)) return false;
  $dur = (int)cfg('duration');
  $end = date('Y-m-d H:i:s', ts($when) + $dur * 60);
  $sql = "SELECT s.id FROM shk_slots s
            JOIN shk_experts e ON e.id = s.expert_id
           WHERE (e.link IS NULL OR e.link = '')
             AND s.dt < ? AND DATE_ADD(s.dt, INTERVAL ? MINUTE) > ?";
  $args = [$end, $dur, $when];
  if($exceptExpert){ $sql .= " AND s.expert_id <> ?"; $args[] = (int)$exceptExpert; }
  return (bool)one($sql . " LIMIT 1", $args);
}
/* Занятые интервалы общего кабинета — чтобы специалист видел общий календарь */
function sharedBusy($exceptExpert = null, $days = 21){
  $rows = all(
    "SELECT s.dt, s.expert_id, s.booking_id, e.name FROM shk_slots s
       JOIN shk_experts e ON e.id = s.expert_id
      WHERE s.dt > ? AND s.dt < ? ORDER BY s.dt",
    [date('Y-m-d H:i:s', time() - 3600), date('Y-m-d H:i:s', time() + $days * 86400)]);
  $out = [];
  foreach($rows as $r){
    if($exceptExpert && (int)$r['expert_id'] === (int)$exceptExpert) continue;
    $out[] = ['dt' => $r['dt'], 'who' => $r['name'], 'booked' => (bool)$r['booking_id']];
  }
  return $out;
}

/* Свободные времена: клиент видит только время, без имён.
   Возвращаем ещё и направления, свободные на этот час. */
/* $tolko — показать время только этого специалиста (ученица выбрала её) */
function openTimes($tolko = 0){
  $quota = (int)cfg('quota');
  $rows  = all(
    "SELECT s.dt, s.expert_id, e.direction, e.topics, e.pairs FROM shk_slots s
       JOIN shk_experts e ON e.id = s.expert_id
      WHERE s.booking_id IS NULL AND e.active = 1 AND s.dt > ?
        " . ($tolko ? " AND s.expert_id = " . (int)$tolko : "") . "
      ORDER BY s.dt", [now()]);
  $out = [];
  foreach($rows as $r){
    if(quotaOn() && loadOf($r['expert_id']) >= $quota) continue;
    if(!isset($out[$r['dt']])) $out[$r['dt']] = ['dirs' => [], 'topics' => [], 'pairTopics' => [], 'experts' => []];
    /* чьё это время — чтобы ученица могла отобрать часы своей специалистки */
    $out[$r['dt']]['experts'][(int)$r['expert_id']] = true;
    $d = $r['direction'] !== '' ? $r['direction'] : 'Без направления';
    $out[$r['dt']]['dirs'][$d] = true;
    foreach(topicsOf($r) as $t){
      $out[$r['dt']]['topics'][$t] = true;
      if(takesPairs($r)) $out[$r['dt']]['pairTopics'][$t] = true;
    }
  }
  $res = [];
  foreach($out as $dt => $sets){
    if(sharedRoom() && busyInterval($dt)) continue;   /* общий кабинет уже занят на этот интервал */
    $res[] = ['dt' => $dt, 'dirs' => array_keys($sets['dirs']),
              'topics' => array_keys($sets['topics']),
              'pairTopics' => array_keys($sets['pairTopics']),
              'experts' => array_map('intval', array_keys($sets['experts']))];
  }
  return $res;
}
function openTimeList(){
  $r = [];
  foreach(openTimes() as $t) $r[] = $t['dt'];
  return $r;
}
/* У специалиста есть своя постоянная комната — значит, встречи ведёт у себя,
   и общий Zoom его не касается ни ссылкой, ни календарём. */
function ownRoom($expertRow){ return trim((string)($expertRow['link'] ?? '')) !== ''; }

/* ── Запас одноразовых ссылок ─────────────────────────────────
   Одна постоянная комната на всех — это возможность зайти на чужую
   консультацию: «ой, посмотрю, какая сессия идёт». Для психологической
   помощи так нельзя. Поэтому у каждой встречи своя ссылка: специалист
   заранее нарезает их пачкой (SaluteJazz, Телемост), система выдаёт по
   одной и следит, чтобы запас не кончился. */
function linksFree($expertId){
  $r = one("SELECT COUNT(*) c FROM shk_links WHERE expert_id=? AND booking_id IS NULL", [(int)$expertId]);
  return (int)$r['c'];
}
/* Берём ссылку под запись. Вызывается внутри транзакции записи. */
function linkTake($expertId, $bookingId){
  $row = one("SELECT id, url FROM shk_links WHERE expert_id=? AND booking_id IS NULL ORDER BY id LIMIT 1 FOR UPDATE",
             [(int)$expertId]);
  if(!$row) return '';
  q("UPDATE shk_links SET booking_id=?, used_at=? WHERE id=?", [(int)$bookingId, now(), $row['id']]);
  return $row['url'];
}
/* Встреча отменилась — ссылка не пригодилась, возвращаем в запас. */
function linkRelease($bookingId){
  q("UPDATE shk_links SET booking_id=NULL, used_at=NULL WHERE booking_id=?", [(int)$bookingId]);
}

function meetingLink($expertRow, $code){
  $l = trim((string)$expertRow['link']);
  if($l !== '') return $l;                        /* своя комната — в приоритете */
  if(sharedRoom()){
    $s = trim((string)cfg('shared_link'));
    if($s !== '') return $s;
  }
  if(cfg('auto_link') === 'jitsi') return 'https://meet.jit.si/psy-' . strtolower($code) . '-' . substr(bin2hex(random_bytes(3)), 0, 5);
  return '';
}

/* Приводит ссылку записи в соответствие с тем, как работает её специалист:
   своя комната — ставим её и убираем конференцию Zoom; нет своей — создаём Zoom. */
function syncMeetingLink($b){
  $e = expert($b['expert_id']);
  if(!$e) return $b;
  if(ownRoom($e)){
    if(!empty($b['zoom_id'])){
      zoomDrop($b['zoom_id']);
      q("UPDATE shk_bookings SET zoom_id='' WHERE id=?", [$b['id']]);
    }
    /* Если встрече уже выдана одноразовая ссылка — не трогаем её:
       перевод на общую комнату открыл бы встречу посторонним. */
    $fromPool = one("SELECT id FROM shk_links WHERE booking_id=?", [$b['id']]);
    if(!$fromPool && trim((string)$b['link']) !== trim((string)$e['link']))
      q("UPDATE shk_bookings SET link=? WHERE id=?", [trim((string)$e['link']), $b['id']]);
    return one("SELECT * FROM shk_bookings WHERE id=?", [$b['id']]);
  }
  if(zoomOn() && empty($b['zoom_id'])) return attachZoom($b);
  return $b;
}

/* ── Zoom: конференция на каждую встречу ─────────────────────
   Server-to-Server OAuth. Ключи вводит координатор в настройках,
   наружу они не отдаются никогда. api.zoom.us с хостинга доступен. */
function zoomOn(){
  return cfg('zoom_on') === '1' && cfg('zoom_account') !== '' && cfg('zoom_id') !== '' && cfg('zoom_secret') !== '';
}
function zoomToken(){
  $t = cfg('zoom_token'); $exp = (int)cfg('zoom_token_exp');
  if($t !== '' && $exp > time() + 60) return $t;
  $c = curl_init('https://zoom.us/oauth/token?grant_type=account_credentials&account_id=' . urlencode(cfg('zoom_account')));
  curl_setopt_array($c, [
    CURLOPT_POST => 1, CURLOPT_RETURNTRANSFER => 1, CURLOPT_TIMEOUT => 20,
    CURLOPT_USERPWD => cfg('zoom_id') . ':' . cfg('zoom_secret'),
    CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded'],
  ]);
  $r = curl_exec($c); $err = curl_error($c); $code = curl_getinfo($c, CURLINFO_HTTP_CODE); curl_close($c);
  if($r === false) throw new Exception('Zoom недоступен: ' . $err);
  $j = json_decode($r, true);
  if($code !== 200 || empty($j['access_token']))
    throw new Exception('Zoom не принял ключи (' . $code . '): ' . mb_substr((string)($j['reason'] ?? $r), 0, 120));
  setSetting('zoom_token', $j['access_token']);
  setSetting('zoom_token_exp', (string)(time() + (int)($j['expires_in'] ?? 3600)));
  return $j['access_token'];
}
function zoomApi($method, $path, $body = null){
  $c = curl_init('https://api.zoom.us/v2' . $path);
  $opt = [
    CURLOPT_RETURNTRANSFER => 1, CURLOPT_TIMEOUT => 25, CURLOPT_CUSTOMREQUEST => $method,
    CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . zoomToken(), 'Content-Type: application/json'],
  ];
  if($body !== null) $opt[CURLOPT_POSTFIELDS] = json_encode($body, JSON_UNESCAPED_UNICODE);
  curl_setopt_array($c, $opt);
  $r = curl_exec($c); $err = curl_error($c); $code = curl_getinfo($c, CURLINFO_HTTP_CODE); curl_close($c);
  if($r === false) throw new Exception('Zoom недоступен: ' . $err);
  $j = json_decode($r, true);
  if($code >= 300) throw new Exception('Zoom ответил ' . $code . ': ' . mb_substr((string)($j['message'] ?? $r), 0, 160));
  return $j ?: [];
}
/* Время Zoom принимает в UTC — переводим из нашего пояса */
function zoomStart($when){
  $d = new DateTime($when, new DateTimeZone(cfg('tz') ?: 'Europe/Moscow'));
  $d->setTimezone(new DateTimeZone('UTC'));
  return $d->format('Y-m-d\TH:i:s\Z');
}
function zoomCreate($b, $expertName){
  $j = zoomApi('POST', '/users/me/meetings', [
    'topic'      => 'Консультация · ' . $expertName,
    'type'       => 2,
    'start_time' => zoomStart($b['dt']),
    'duration'   => (int)cfg('duration'),
    'timezone'   => cfg('tz') ?: 'Europe/Moscow',
    'agenda'     => trim($b['category'] . ' ' . $b['note']),
    'settings'   => [
      'use_pmi'          => false,    /* КАЖДОЙ встрече — своя комната, иначе все попадают в одну */
      'join_before_host' => true,     /* специалист начинает сам, без хозяина аккаунта */
      'jbh_time'         => 5,
      'waiting_room'     => false,
      'participant_video'=> true,
      'host_video'       => true,
      'mute_upon_entry'  => false,
      'auto_recording'   => 'none',
    ],
  ]);
  return ['id' => (string)($j['id'] ?? ''), 'url' => (string)($j['join_url'] ?? '')];
}
function zoomDrop($id){
  if(!$id) return;
  try{ zoomApi('DELETE', '/meetings/' . $id); }catch(Throwable $e){ logit('zoom', 'удаление: ' . $e->getMessage()); }
}
function zoomMove($id, $when){
  if(!$id) return false;
  try{
    zoomApi('PATCH', '/meetings/' . $id, [
      'start_time' => zoomStart($when),
      'duration'   => (int)cfg('duration'),
      'timezone'   => cfg('tz') ?: 'Europe/Moscow',
    ]);
    return true;
  }catch(Throwable $e){ logit('zoom', 'перенос: ' . $e->getMessage()); return false; }
}
/* Конференция создаётся после коммита: держать транзакцию открытой
   на время похода в интернет нельзя. Не получилось — остаётся запасная ссылка. */
function attachZoom($b){
  if(!zoomOn()) return $b;
  $e = expert($b['expert_id']);
  try{
    $m = zoomCreate($b, $e ? $e['name'] : 'специалист');
    if($m['url'] !== ''){
      q("UPDATE shk_bookings SET link=?, zoom_id=? WHERE id=?", [$m['url'], $m['id'], $b['id']]);
      $b['link'] = $m['url']; $b['zoom_id'] = $m['id'];
    }
  }catch(Throwable $ex){
    logit('zoom', 'создание: ' . $ex->getMessage());
    adminNotify("⚠️ Zoom не создал конференцию на " . fmtRu($b['dt']) . ".\n" . $ex->getMessage() .
                "\nУченице ушла запасная ссылка: " . ($b['link'] ?: 'её нет — вышлите вручную'));
  }
  return $b;
}

/* ── Передача лида во внешний агрегатор ──────────────────────
   POST с полями name / phone / utm_*. Уходит после создания записи;
   если не дошло — повторяем в планировщике, до пяти раз. */
function hookOn(){ return cfg('hook_on') === '1' && trim((string)cfg('hook_url')) !== ''; }
function hookPayload($b){
  return [
    'name'         => (string)$b['client_name'],
    'phone'        => (string)$b['contact'],
    'utm_source'   => (string)($b['utm_source'] ?? ''),
    'utm_medium'   => (string)($b['utm_medium'] ?? ''),
    'utm_campaign' => (string)($b['utm_campaign'] ?? ''),
    'utm_term'     => (string)($b['utm_term'] ?? ''),
  ];
}
function hookPost($payload){
  $url = trim((string)cfg('hook_url'));
  if($url === '') return [false, 'адрес не задан'];
  $c = curl_init($url);
  curl_setopt_array($c, [
    CURLOPT_POST => 1, CURLOPT_RETURNTRANSFER => 1, CURLOPT_TIMEOUT => 15,
    CURLOPT_CONNECTTIMEOUT => 8,
    CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
    CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE),
  ]);
  $r = curl_exec($c); $err = curl_error($c); $code = curl_getinfo($c, CURLINFO_HTTP_CODE); curl_close($c);
  if($r === false) return [false, $err];
  return [$code >= 200 && $code < 300, 'HTTP ' . $code . ' ' . mb_substr((string)$r, 0, 120)];
}
function hookSend($b){
  if(!hookOn()) return;
  list($ok, $msg) = hookPost(hookPayload($b));
  q("UPDATE shk_bookings SET hook_status=?, hook_tries=hook_tries+1 WHERE id=?",
    [$ok ? 'sent' : 'pending', $b['id']]);
  if(!$ok) logit('hook', 'запись ' . $b['code'] . ': ' . $msg);
}
function hookRetry(){
  if(!hookOn()) return;
  $rows = all("SELECT * FROM shk_bookings
                WHERE hook_status='pending' AND hook_tries < 5 AND created_at > ?",
              [date('Y-m-d H:i:s', time() - 3*86400)]);
  foreach($rows as $b) hookSend($b);
}

/* ── Запись (атомарно) ──────────────────────────────────────── */
/* ── «Свой» специалист ───────────────────────────────────────
   Если клиентка уже была на консультации, повторная запись должна вести
   к тому же человеку: рассказывать историю заново — самая дорогая часть
   встречи. Ищем по контакту или почте, отменённые записи не в счёт. */
/* «Свой» специалист — тот, у кого ученица была в прошлый раз.
   Ищем по ключу ученицы. Раньше искали по контакту, а у 142 записей
   там прочерк — и «своим» становился специалист случайной чужой
   ученицы с таким же прочерком. */
function stickyExpert($contact, $email, $ckey = ''){
  $ckey = trim((string)$ckey);
  if($ckey === ''){
    $ckey = clientKey($contact, $email, '');
    /* ключ из пустого контакта ненадёжен — лучше никого, чем чужого */
    if($ckey === '' || strpos($ckey, 'x:') === 0 || strpos($ckey, 'c:') === 0) return 0;
  }
  $r = one("SELECT b.expert_id FROM shk_bookings b
              JOIN shk_experts e ON e.id = b.expert_id
             WHERE b.ckey = ? AND b.status <> 'cancelled' AND e.active = 1
             ORDER BY b.dt DESC LIMIT 1", [$ckey]);
  return $r ? (int)$r['expert_id'] : 0;
}

/* Кто ведёт встречи: имя, роль и сколько у него свободных часов.
   Ученице показываем это, чтобы она могла вернуться к своей. */
function specialistyDlyaVybora($ckey = ''){
  $svoy = $ckey !== '' ? stickyExpert('', '', $ckey) : 0;
  $out = [];
  foreach(bySurname(all("SELECT * FROM shk_experts WHERE active=1")) as $e){
    $svobodno = (int)one("SELECT COUNT(*) c FROM shk_slots WHERE expert_id=? AND booking_id IS NULL AND dt>?",
                         [$e['id'], now()])['c'];
    $blizhayshiy = one("SELECT MIN(dt) d FROM shk_slots WHERE expert_id=? AND booking_id IS NULL AND dt>?",
                       [$e['id'], now()])['d'];
    $bylo = $ckey !== '' ? (int)one("SELECT COUNT(*) c FROM shk_bookings WHERE ckey=? AND expert_id=? AND status<>'cancelled'",
                                    [$ckey, $e['id']])['c'] : 0;
    $out[] = ['id' => (int)$e['id'], 'name' => $e['name'], 'role' => specRole($e),
              'free' => $svobodno, 'next' => $blizhayshiy ?: '',
              'byla' => $bylo, 'svoy' => ((int)$e['id'] === $svoy)];
  }
  /* «Своя» — первой: к ней и вернутся */
  usort($out, function($a, $b){
    if($a['svoy'] !== $b['svoy']) return $a['svoy'] ? -1 : 1;
    if(($a['byla'] > 0) !== ($b['byla'] > 0)) return $a['byla'] > 0 ? -1 : 1;
    return $b['free'] <=> $a['free'];
  });
  return $out;
}

/* ── Лист ожидания ───────────────────────────────────────────
   Свободных часов может не быть вовсе. Раньше такие люди оседали
   в хвосте таблицы куратора и ждали, пока о них вспомнят; теперь
   они оставляют контакт сами и получают сигнал, как только время
   открылось. Первым записался — первым и получил. */
function waitCode(){
  $a = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
  for($try = 0; $try < 40; $try++){
    $c = '';
    for($i = 0; $i < 5; $i++) $c .= $a[random_int(0, strlen($a) - 1)];
    if(!one("SELECT code FROM shk_wait WHERE code=?", [$c])) return $c;
  }
  return strtoupper(substr(bin2hex(random_bytes(4)), 0, 6));
}

/* Записалась — значит, ждать больше нечего. */
function waitClose($contact, $email){
  $contact = trim((string)$contact);
  $email   = trim((string)$email);
  $where = []; $args = [now()];
  if($contact !== ''){ $where[] = 'contact = ?'; $args[] = $contact; }
  if($email   !== ''){ $where[] = 'email = ?';   $args[] = $email;   }
  if(count($args) === 1) return 0;
  $st = q("UPDATE shk_wait SET status='done', closed_at=?
            WHERE status <> 'done' AND (" . implode(' OR ', $where) . ")", $args);
  return $st->rowCount();
}

/* Специалист открыл часы — зовём тех, кто ждёт подходящую тему.
   Не чаще раза в сутки на человека: рассылка на каждый добавленный
   слот превратила бы полезный сигнал в спам. */
function notifyWaiting($expertId){
  $e = expert($expertId);
  if(!$e) return 0;
  $rows = all("SELECT * FROM shk_wait
                WHERE status='waiting'
                  AND (notified_at IS NULL OR notified_at < ?)
                ORDER BY created_at", [date('Y-m-d H:i:s', time() - 24*3600)]);
  $sent = 0;
  foreach($rows as $w){
    if($w['topic'] !== '' && !worksWith($e, $w['topic'])) continue;
    if((int)$w['pair'] === 1 && !takesPairs($e)) continue;
    $text = "Появилось свободное время у специалиста.\n\n" .
            ($w['topic'] !== '' ? "Тема: " . $w['topic'] . "\n" : '') .
            "Записаться: " . siteUrl() . "\n\n" .
            "Время занимает та, кто записалась первой — если оно вам подходит, лучше не откладывать.";
    $ch = $w['tg_chat'] ? (int)$w['tg_chat'] : 0;
    $r = tell($ch, $w['email'], 'Появилось время у специалиста', $text, false,
              phoneOf($w['contact']),
              'Появилось время у специалиста. Записаться: ' . siteUrl());
    if($r !== ''){
      q("UPDATE shk_wait SET notified_at=? WHERE id=?", [now(), $w['id']]);
      $sent++;
    }
  }
  if($sent) logit('wait', "позвали из очереди: $sent");
  return $sent;
}

/* $forceExpert — запись рукой куратора: ведём к этому специалисту И обходим
   лимит с паузой (куратор сам решает). $hochet — выбор самой ученицы:
   специалиста уважаем, но все правила остаются в силе. Путать их нельзя. */
function makeBooking($when, $name, $contact, $cat, $note, $forceExpert = null, $ignoreQuota = false, $direction = '', $email = '', $utm = [], $pair = false, $phone = '', $tgNick = '', $course = '', $knownCkey = '', $hochet = 0){
  initTables();
  /* Запас консультаций. Координатор, записывая рукой, лимит перешагивает:
     он и есть тот человек, который решает добавить встречу. */
  /* У залогиненной ученицы ключ уже известен — считать его заново из
     контакта нельзя: получится другой ключ, и запись заведёт карточку-дубль. */
  $ckey = $knownCkey !== '' ? $knownCkey : clientKey($contact, $email, $name);
  if(!$forceExpert){
    $cl0 = clientFind($ckey);
    if($cl0 && (int)$cl0['blocked'] === 1) return 'blocked';
  }
  if(!$forceExpert){
    $near = cooldownConflict($ckey, $when);
    if($near) return 'soon:' . $near['dt'];
  }
  if(limitOn() && !$forceExpert){
    $cl = clientTouch($name, $contact, $email, $course, $ckey);
    $allowed = clientAllowed($cl);
    if($allowed > 0 && clientUsed($ckey) >= $allowed) return 'limit';
  }
  $quota = (int)cfg('quota');
  $pdo   = db();
  $pdo->beginTransaction();
  try{
    if(sharedRoom() && busyInterval($when)){ $pdo->rollBack(); return null; }
    $cands = all(
      "SELECT s.id, s.expert_id FROM shk_slots s
         JOIN shk_experts e ON e.id = s.expert_id
        WHERE s.dt = ? AND s.booking_id IS NULL AND e.active = 1
          AND (? = '' OR e.direction = ?)
        ORDER BY s.id FOR UPDATE", [$when, $direction, $direction]);
    $best = null; $bestLoad = PHP_INT_MAX;
    if($forceExpert){
      /* координатор назначает конкретного человека — при необходимости открываем слот за него */
      foreach($cands as $c) if((int)$c['expert_id'] === (int)$forceExpert){ $best = $c; break; }
      if(!$best){
        $fe = expert($forceExpert);
        if(!$fe){ $pdo->rollBack(); return null; }
        $busy = one("SELECT id FROM shk_slots WHERE expert_id=? AND dt=?", [$forceExpert, $when]);
        if($busy){ $pdo->rollBack(); return null; }   /* это время у него уже занято */
        q("INSERT INTO shk_slots(expert_id, dt, created_at) VALUES(?,?,?)", [$forceExpert, $when, now()]);
        $best = ['id' => (int)$pdo->lastInsertId(), 'expert_id' => (int)$forceExpert];
      }
    }else{
      /* Сначала «свой» специалист — тот, у кого она уже была. Непрерывность
         важнее равномерной загрузки, поэтому квота здесь не останавливает;
         но открытый слот всё равно нужен — выдумывать время мы не будем. */
      /* Выбрала конкретную — ведём к ней, если у неё открыт этот час */
      if($hochet) foreach($cands as $c){
        if((int)$c['expert_id'] === (int)$hochet){ $best = $c; break; }
      }
      if(!$best && cfg('sticky') === '1'){
        $prev = stickyExpert($contact, $email, $ckey);
        if($prev) foreach($cands as $c){
          if((int)$c['expert_id'] !== $prev) continue;
          $ex = expert($prev);
          if($ex && worksWith($ex, $cat) && (!$pair || takesPairs($ex))) $best = $c;
          break;
        }
      }
      /* Два прохода: сначала те, кто сдал отчёты, и только если таких нет —
         остальные. Запись не должна срываться из-за чужой дисциплины. */
      if(!$best) foreach([false, true] as $withDebtors){
        $bestLoad = PHP_INT_MAX; $best = null;
        foreach($cands as $c){
          $ex = expert($c['expert_id']);
          if(!$ex || !worksWith($ex, $cat)) continue;      /* тема, с которой она работает */
          if($pair && !takesPairs($ex)) continue;          /* парные берёт не каждый */
          $l = loadOf($c['expert_id']);
          if(!$ignoreQuota && quotaOn() && $l >= $quota) continue;
          if(!$withDebtors && reportDebt($c['expert_id']) > 0) continue;
          if($l < $bestLoad){ $bestLoad = $l; $best = $c; }
        }
        if($best) break;
      }
    }
    if(!$best){ $pdo->rollBack(); return null; }

    $e    = expert($best['expert_id']);
    $code = newCode();
    $link = meetingLink($e, $code);
    q("INSERT INTO shk_bookings(code,expert_id,slot_id,dt,client_name,contact,email,category,note,link,direction,course,ckey,
         utm_source,utm_medium,utm_campaign,utm_term,pair,phone,tg_nick,status,created_at)
       VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?, ?, ?)",
       [$code, $e['id'], $best['id'], $when, $name, $contact, $email, $cat, $note, $link,
        ($direction !== '' ? $direction : (string)$e['direction']), $course, $ckey,
        mb_substr((string)($utm['utm_source'] ?? ''), 0, 110),
        mb_substr((string)($utm['utm_medium'] ?? ''), 0, 110),
        mb_substr((string)($utm['utm_campaign'] ?? ''), 0, 110),
        mb_substr((string)($utm['utm_term'] ?? ''), 0, 110), $pair ? 1 : 0,
        mb_substr($phone, 0, 20), ltrim(mb_substr($tgNick, 0, 60), '@'),
        ($forceExpert || !moderationOn()) ? 'booked' : 'pending', now()]);
    $bid = (int)$pdo->lastInsertId();
    /* Если ученица уже подключала бота, новая запись должна знать об этом
       сразу — иначе напоминания уйдут на почту, хотя телеграм под рукой. */
    $chatEe = one("SELECT tg_chat FROM shk_clients WHERE ckey=? AND COALESCE(tg_chat,0)<>0", [$ckey]);
    if($chatEe) q("UPDATE shk_bookings SET tg_chat=? WHERE id=?", [$chatEe['tg_chat'], $bid]);
    /* Одноразовая ссылка важнее постоянной комнаты: на чужую встречу
       по ней уже не зайдёшь. Заявке ссылку не выдаём — запас не резиновый,
       а заявку могут и отклонить. */
    if(($forceExpert || !moderationOn())){
      $one = linkTake($e['id'], $bid);
      if($one !== ''){ q("UPDATE shk_bookings SET link=? WHERE id=?", [$one, $bid]); $link = $one; }
    }
    q("UPDATE shk_slots SET booking_id=? WHERE id=?", [$bid, $best['id']]);
    $pdo->commit();

    /* Напоминания, срок которых уже прошёл, не шлём */
    $t   = ts($when);
    $dur = (int)cfg('duration');
    if(time() > $t - 24*3600) markSent($bid, 'r24');
    if(time() > $t - 2*3600){ markSent($bid, 'r2'); markSent($bid, 'e2'); }
    if(time() > $t - 15*60) markSent($bid, 'r15');

    $b = one("SELECT * FROM shk_bookings WHERE id=?", [$bid]);

    /* Заявка — ещё не встреча: ни ссылки, ни напоминаний, пока куратор
       не подтвердил. Ученице сообщаем, что заявка принята. */
    if($b['status'] === 'pending'){
      waitClose($b['contact'], $b['email']);
      histAdd($bid, 'new', 'Заявка от ученицы, ждёт подтверждения');
      adminNotify("📝 Заявка: " . $b['client_name'] . ", " . fmtRu($b['dt']) . ", " . $e['name'] .
                  "\nПодтвердить: " . siteUrl() . "koordinator/");
      if(!empty($b['email']))
        sendMail($b['email'], 'Заявка принята — ' . fmtRu($b['dt']),
          "Здравствуйте, " . $b['client_name'] . "!\n\n" .
          "Заявка на " . fmtRu($b['dt']) . " принята, время за вами держится.\n" .
          "Куратор подтвердит запись в течение суток — тогда придёт ссылка на встречу.\n\n" .
          "Код заявки: " . $b['code'] . "\n" .
          "Посмотреть или отменить: " . siteUrl() . "?code=" . $b['code']);
      return $b;
    }

    if(!ownRoom($e)) $b = attachZoom($b);
    hookSend($b);
    if(empty($b['tg_chat']) && smsOn())
      smsSend(bookingPhone($b), $b['client_name'] . ', вы записаны ' . date('d.m', ts($b['dt'])) . ' в ' .
        date('H:i', ts($b['dt'])) . ', ' . $e['name'] . '. Ссылка: ' . smsUrl($b));
    if(!empty($b['email'])){
      sendMail($b['email'], 'Вы записаны — ' . fmtRu($b['dt']),
        "Здравствуйте, " . $b['client_name'] . "!\n\n" . meetingCard($b, $e) . "\n\n" .
        "Напомним за сутки и за два часа.\n" .
        "Перенести или отменить: " . siteUrl() . "?code=" . $b['code'] .
        ($e['tg_chat'] || cfg('bot_user') ? "\nНапоминания в Telegram: " . botLink('b' . $b['code']) : ''));
    }
    waitClose($b['contact'], $b['email']);
    notifyNewBooking($b, $e);
    return $b;
  }catch(Throwable $ex){
    if($pdo->inTransaction()) $pdo->rollBack();
    logit('book', $ex->getMessage());
    return null;
  }
}

/* Куратор подтвердил заявку: только теперь это настоящая запись —
   выдаём ссылку, включаем напоминания, сообщаем обеим сторонам. */
function approveBooking($b, $who = 'координатор', $manualLink = ''){
  if(!$b || $b['status'] !== 'pending') return false;
  $e = expert($b['expert_id']);
  if(!$e) return false;
  q("UPDATE shk_bookings SET status='booked' WHERE id=?", [$b['id']]);
  $b = one("SELECT * FROM shk_bookings WHERE id=?", [$b['id']]);

  /* Куратор может вписать ссылку руками — тогда запас не трогаем. */
  if($manualLink !== ''){
    q("UPDATE shk_bookings SET link=? WHERE id=?", [mb_substr($manualLink, 0, 290), $b['id']]);
    $b = one("SELECT * FROM shk_bookings WHERE id=?", [$b['id']]);
  }
  if(trim((string)$b['link']) === ''){
    $one = linkTake($e['id'], $b['id']);
    if($one !== '') q("UPDATE shk_bookings SET link=? WHERE id=?", [$one, $b['id']]);
    $b = one("SELECT * FROM shk_bookings WHERE id=?", [$b['id']]);
  }
  if(!ownRoom($e) && trim((string)$b['link']) === '') $b = attachZoom($b);

  /* Напоминания, срок которых уже прошёл, не шлём */
  $t = ts($b['dt']);
  if(time() > $t - 24*3600) markSent($b['id'], 'r24');
  if(time() > $t - 2*3600){ markSent($b['id'], 'r2'); markSent($b['id'], 'e2'); }

  hookSend($b);
  if(!empty($b['email']))
    sendMail($b['email'], 'Запись подтверждена — ' . fmtRu($b['dt']),
      "Здравствуйте, " . $b['client_name'] . "!\n\n" . meetingCard($b, $e) . "\n\n" .
      "Напомним за сутки и за два часа.\n" .
      "Перенести или отменить: " . siteUrl() . "?code=" . $b['code']);
  if(empty($b['tg_chat']) && smsOn())
    smsSend(bookingPhone($b), $b['client_name'] . ', запись подтверждена: ' .
      date('d.m', ts($b['dt'])) . ' в ' . date('H:i', ts($b['dt'])) . '. Ссылка: ' . smsUrl($b));
  histAdd($b['id'], 'ok', 'Запись подтверждена (' . $who . ')');
  notifyNewBooking($b, $e);
  return true;
}

/* Заявка отклонена: слот освобождается, время снова доступно другим. */
function rejectBooking($b, $why, $who = 'координатор'){
  if(!$b || $b['status'] !== 'pending') return false;
  q("UPDATE shk_bookings SET status='cancelled' WHERE id=?", [$b['id']]);
  q("UPDATE shk_slots SET booking_id=NULL WHERE id=? AND booking_id=?", [$b['slot_id'], $b['id']]);
  linkRelease($b['id']);
  histAdd($b['id'], 'no', 'Заявка отклонена (' . $who . ')' . ($why !== '' ? ': ' . $why : ''));
  if(!empty($b['email']))
    sendMail($b['email'], 'Заявка отклонена — ' . fmtRu($b['dt']),
      "Здравствуйте, " . $b['client_name'] . "!\n\n" .
      "Заявку на " . fmtRu($b['dt']) . " подтвердить не получилось" .
      ($why !== '' ? ": " . $why : '.') . "\n\nНапишите куратору — разберёмся вместе.");
  return true;
}

function histAdd($bookingId, $kind, $txt){
  q("INSERT INTO shk_history(booking_id, at, kind, txt) VALUES(?,?,?,?)",
    [(int)$bookingId, now(), $kind, mb_substr($txt, 0, 380)]);
}

/* ── Снять специалиста и искать нового ───────────────────────
   Тихая внутренняя операция: клиентке не пишем, время за ней остаётся,
   прежний специалист перестаёт видеть встречу у себя. */
function unassignBooking($b, $reason, $who = 'координатор'){
  if(!$b || $b['status'] === 'cancelled') return [false, 'Запись отменена.'];
  $old = expert($b['expert_id']);
  q("UPDATE shk_slots SET booking_id=NULL WHERE id=? AND booking_id=?", [$b['slot_id'], $b['id']]);
  q("UPDATE shk_bookings SET status='searching' WHERE id=?", [$b['id']]);
  /* напоминания не шлём, пока нет специалиста */
  q("DELETE FROM shk_sent WHERE booking_id=? AND kind IN ('r24','r2','r15','e2','eask','rep')", [$b['id']]);
  histAdd($b['id'], 'search', 'Снят специалист ' . ($old['name'] ?? '—') . ', ищем нового' .
          ($reason !== '' ? '. Причина: ' . $reason : '') . ' (' . $who . ')');
  return [true, one("SELECT * FROM shk_bookings WHERE id=?", [$b['id']])];
}

/* Назначить специалиста на «висящую» запись — вот теперь клиентке пишем. */
function assignBooking($b, $newExpertId, $who = 'координатор'){
  $new = expert($newExpertId);
  if(!$new) return [false, 'Такого специалиста нет.'];
  if(!empty($b['pair']) && !takesPairs($new))
    return [false, $new['name'] . ' не берёт парные консультации, а эта встреча парная.'];
  if(!worksWith($new, $b['category']))
    return [false, $new['name'] . ' не работает с темой «' . $b['category'] . '». Отметьте тему у неё в кабинете или выберите другого.'];

  $pdo = db();
  $pdo->beginTransaction();
  try{
    $slot = one("SELECT * FROM shk_slots WHERE expert_id=? AND dt=? FOR UPDATE", [$new['id'], $b['dt']]);
    if($slot && $slot['booking_id'] && (int)$slot['booking_id'] !== (int)$b['id']){
      $pdo->rollBack();
      return [false, 'У ' . $new['name'] . ' это время уже занято.'];
    }
    if(!$slot){
      q("INSERT INTO shk_slots(expert_id, dt, created_at) VALUES(?,?,?)", [$new['id'], $b['dt'], now()]);
      $slotId = (int)$pdo->lastInsertId();
    }else $slotId = (int)$slot['id'];
    q("UPDATE shk_slots SET booking_id=? WHERE id=?", [$b['id'], $slotId]);
    q("UPDATE shk_bookings SET expert_id=?, slot_id=?, direction=?, status='booked' WHERE id=?",
      [$new['id'], $slotId, (string)$new['direction'], $b['id']]);
    $pdo->commit();
  }catch(Throwable $ex){
    if($pdo->inTransaction()) $pdo->rollBack();
    return [false, 'Не получилось: ' . $ex->getMessage()];
  }

  $nb = syncMeetingLink(one("SELECT * FROM shk_bookings WHERE id=?", [$b['id']]));
  histAdd($nb['id'], 'assign', 'Назначен специалист ' . $new['name'] . ' (' . $who . ')');

  tell($new['tg_chat'], $new['email'] ?? '', 'Вам передали встречу — ' . fmtRu($nb['dt']),
    "🔔 Вам передали встречу\n" . fmtRu($nb['dt']) . (!empty($nb['pair']) ? " · ПАРНАЯ" : "") .
    "\n\nУченица: " . $nb['client_name'] . ($nb['category'] !== '' ? "\nТема: " . $nb['category'] : '') .
    ($nb['note'] ? "\n«" . $nb['note'] . "»" : '') . "\nСвязь: " . $nb['contact'] .
    "\nСсылка: " . ($nb['link'] ?: '—'), true);

  $ctxt = "Ваша встреча " . fmtRu($nb['dt']) . " подтверждена.\n" . specRole($new) . ": " . $new['name'] .
          "\nСсылка: " . ($nb['link'] ?: 'пришлём отдельно');
  $sms  = 'Встреча ' . date('d.m', ts($nb['dt'])) . ' в ' . date('H:i', ts($nb['dt'])) . ', ' .
          $new['name'] . '. Ссылка: ' . smsUrl($nb);
  $via = tell($nb['tg_chat'], $nb['email'], 'Ваша встреча подтверждена', $ctxt, true, bookingPhone($nb), $sms);
  histAdd($nb['id'], 'notify', 'Ученице отправлено подтверждение с новым специалистом' .
          ($via ? ' (' . ['tg'=>'Telegram','sms'=>'SMS','mail'=>'почта'][$via] . ')' : ' — канала нет, сообщите вручную'));
  adminNotify("✅ Назначен специалист: " . $new['name'] . "\n" . $nb['client_name'] . ", " . fmtRu($nb['dt']));
  return [true, $nb, $via];
}

/* ── Замена специалиста ──────────────────────────────────────
   Встреча остаётся: то же время, тот же клиент, та же ссылка.
   Меняется только тот, кто её проводит — и это попадает в историю. */
function changeExpert($b, $newExpertId, $reason, $who = 'координатор'){
  $new = expert($newExpertId);
  if(!$new) return [false, 'Такого специалиста нет.'];
  if((int)$new['id'] === (int)$b['expert_id']) return [false, 'Это тот же специалист.'];
  if(!empty($b['pair']) && !takesPairs($new))
    return [false, $new['name'] . ' не берёт парные консультации, а эта встреча парная.'];

  $old = expert($b['expert_id']);
  $pdo = db();
  $pdo->beginTransaction();
  try{
    /* нужен слот у нового специалиста на это же время */
    $slot = one("SELECT * FROM shk_slots WHERE expert_id=? AND dt=? FOR UPDATE", [$new['id'], $b['dt']]);
    if($slot && $slot['booking_id'] && (int)$slot['booking_id'] !== (int)$b['id']){
      $pdo->rollBack();
      return [false, 'У ' . $new['name'] . ' это время уже занято другой встречей.'];
    }
    if(!$slot){
      q("INSERT INTO shk_slots(expert_id, dt, created_at) VALUES(?,?,?)", [$new['id'], $b['dt'], now()]);
      $slotId = (int)$pdo->lastInsertId();
    }else{
      $slotId = (int)$slot['id'];
    }
    q("UPDATE shk_slots SET booking_id=NULL WHERE id=? AND booking_id=?", [$b['slot_id'], $b['id']]);
    q("UPDATE shk_slots SET booking_id=? WHERE id=?", [$b['id'], $slotId]);
    q("UPDATE shk_bookings SET expert_id=?, slot_id=?, direction=? WHERE id=?",
      [$new['id'], $slotId, (string)$new['direction'], $b['id']]);
    $pdo->commit();
  }catch(Throwable $ex){
    if($pdo->inTransaction()) $pdo->rollBack();
    logit('change', $ex->getMessage());
    return [false, 'Не получилось: ' . $ex->getMessage()];
  }

  histAdd($b['id'], 'expert', 'Специалист заменён: ' . ($old['name'] ?? '—') . ' → ' . $new['name'] .
          ($reason !== '' ? '. Причина: ' . $reason : '') . ' (' . $who . ')');

  /* конференция та же, меняем только подпись */
  if(zoomOn() && !empty($b['zoom_id']) && !ownRoom($new)){
    try{ zoomApi('PATCH', '/meetings/' . $b['zoom_id'], ['topic' => 'Консультация · ' . $new['name']]); }
    catch(Throwable $e){ logit('zoom', 'смена подписи: ' . $e->getMessage()); }
  }

  $nb = syncMeetingLink(one("SELECT * FROM shk_bookings WHERE id=?", [$b['id']]));
  /* сбрасываем отметки напоминаний нового специалиста */
  q("DELETE FROM shk_sent WHERE booking_id=? AND kind IN ('e2','eask','rep')", [$b['id']]);

  if($old && $old['tg_chat'])
    send($old['tg_chat'], "↔️ Встреча " . fmtRu($nb['dt']) . " с " . $nb['client_name'] .
      " передана другому специалисту. Ваше время свободно.");
  tell($new['tg_chat'], $new['email'] ?? '', 'Вам передали встречу — ' . fmtRu($nb['dt']),
    "↔️ Вам передали встречу\n" . fmtRu($nb['dt']) . "\n\nУченица: " . $nb['client_name'] .
    "\nТема: " . $nb['category'] . ($nb['note'] ? "\n«" . $nb['note'] . "»" : '') .
    "\nСвязь: " . $nb['contact'] . "\nСсылка: " . ($nb['link'] ?: '—'), true);
  $ctxt = "Небольшое изменение: встречу " . fmtRu($nb['dt']) . " проведёт " . $new['name'] .
          ".\nВремя и ссылка прежние: " . ($nb['link'] ?: 'пришлём отдельно');
  tell($nb['tg_chat'], $nb['email'], 'Изменение по встрече ' . fmtRu($nb['dt']), $ctxt, true);
  adminNotify("↔️ Замена специалиста\n" . $nb['client_name'] . ", " . fmtRu($nb['dt']) .
              "\n" . ($old['name'] ?? '—') . " → " . $new['name'] . ($reason !== '' ? "\nПричина: " . $reason : ''));
  return [true, $nb];
}

/* ── Перенос встречи ────────────────────────────────────────
   Старый слот освобождается только после того, как новый занят:
   клиентка не может остаться вообще без места. */
function rescheduleBooking($b, $when, $who){
  if(!$b || $b['status'] === 'cancelled') return null;
  $quota = (int)cfg('quota');
  $pdo   = db();
  $pdo->beginTransaction();
  try{
    if(sharedRoom() && busyInterval($when, $b['id'])){ $pdo->rollBack(); return null; }
    $cands = all(
      "SELECT s.id, s.expert_id FROM shk_slots s
         JOIN shk_experts e ON e.id = s.expert_id
        WHERE s.dt = ? AND s.booking_id IS NULL AND e.active = 1
        ORDER BY s.id FOR UPDATE", [$when]);
    /* сначала пробуем сохранить того же специалиста — клиентка уже настроилась на него */
    $best = null;
    foreach($cands as $c) if((int)$c['expert_id'] === (int)$b['expert_id']){ $best = $c; break; }
    if(!$best){
      $bl = PHP_INT_MAX;
      foreach($cands as $c){
        $l = loadOf($c['expert_id']);
        /* Квота действует, только когда она включена. При quota=0 сравнение
           «загрузка >= 0» отсекало всех — и перенос к другому специалисту
           не работал никогда. */
        if(quotaOn() && $l >= $quota) continue;
        if($l < $bl){ $bl = $l; $best = $c; }
      }
    }
    if(!$best){ $pdo->rollBack(); return null; }

    $oldExpert = (int)$b['expert_id'];
    $oldDt     = $b['dt'];
    q("UPDATE shk_slots SET booking_id=? WHERE id=?", [$b['id'], $best['id']]);
    q("UPDATE shk_slots SET booking_id=NULL WHERE id=? AND booking_id=?", [$b['slot_id'], $b['id']]);
    $e    = expert($best['expert_id']);
    /* Тот же специалист — ссылка остаётся. Новый — старая считается
       потраченной (она уже у ученицы), а новую берём из запаса нового
       специалиста; нет запаса — встреча честно повиснет в «без ссылки». */
    if((int)$best['expert_id'] === $oldExpert && $b['link'] !== ''){
      $link = $b['link'];
    }else{
      $link = meetingLink($e, $b['code']);
      if($link === '') $link = linkTake($e['id'], $b['id']);
    }
    q("UPDATE shk_bookings SET slot_id=?, expert_id=?, dt=?, link=?, status='booked' WHERE id=?",
      [$best['id'], $best['expert_id'], $when, $link, $b['id']]);
    $pdo->commit();
  }catch(Throwable $ex){
    if($pdo->inTransaction()) $pdo->rollBack();
    logit('resched', $ex->getMessage());
    return null;
  }

  /* напоминания едут заново — время другое */
  q("DELETE FROM shk_sent WHERE booking_id=? AND kind IN ('r24','r2','e2','eask','ask','rep')", [$b['id']]);
  $t = ts($when);
  if(time() > $t - 24*3600) markSent($b['id'], 'r24');
  if(time() > $t - 2*3600){ markSent($b['id'], 'r2'); markSent($b['id'], 'e2'); }

  $nb = one("SELECT * FROM shk_bookings WHERE id=?", [$b['id']]);
  $ne0 = expert($nb['expert_id']);
  if($ne0 && ownRoom($ne0)){
    $nb = syncMeetingLink($nb);
  }elseif(zoomOn()){
    if(!empty($nb['zoom_id']) && zoomMove($nb['zoom_id'], $nb['dt'])){ /* та же ссылка, новое время */ }
    else { if(!empty($nb['zoom_id'])) zoomDrop($nb['zoom_id']); $nb = attachZoom($nb); }
  }
  $ne = expert($nb['expert_id']);
  if($nb['tg_chat'])
    send($nb['tg_chat'], "🔄 Встреча перенесена\nБыло: " . fmtRu($oldDt) . "\nСтало: " . fmtRu($nb['dt']) .
      "\n" . specRole($ne) . ": " . $ne['name'] . "\nСсылка: " . ($nb['link'] ?: 'пришлём отдельно'));
  if($ne['tg_chat'])
    send($ne['tg_chat'], "🔄 Перенос ($who)\nБыло: " . fmtRu($oldDt) . "\nСтало: " . fmtRu($nb['dt']) .
      "\nУченица: " . $nb['client_name']);
  if($oldExpert !== (int)$nb['expert_id']){
    $oe = expert($oldExpert);
    if($oe && $oe['tg_chat'])
      send($oe['tg_chat'], "🔄 Встреча " . fmtRu($oldDt) . " с " . $nb['client_name'] .
        " перенесена и ушла другому специалисту. Ваш час снова свободен.");
  }
  histAdd($nb['id'], 'move', 'Перенос: ' . fmtRu($oldDt) . ' → ' . fmtRu($nb['dt']) .
          ($oldExpert !== (int)$nb['expert_id'] ? '. Специалист сменился: ' . $ne['name'] : '') . ' (' . $who . ')');
  adminNotify("🔄 Перенос ($who): " . $nb['client_name'] . "\n" . fmtRu($oldDt) . " → " . fmtRu($nb['dt']) .
              "\nСпециалист: " . $ne['name']);
  return $nb;
}

function cancelBooking($b, $who){
  if(!$b || $b['status'] === 'cancelled') return false;
  q("UPDATE shk_bookings SET status='cancelled' WHERE id=?", [$b['id']]);
  if(!empty($b['zoom_id'])) zoomDrop($b['zoom_id']);
  q("UPDATE shk_slots SET booking_id=NULL WHERE id=? AND booking_id=?", [$b['slot_id'], $b['id']]);
  /* Ссылку в запас НЕ возвращаем: ученица уже получила её в письме.
     Верни мы её — следующая встреча получит ссылку, которая осталась
     на руках у отменившей, и та сможет зайти на чужую консультацию.
     Ссылка остаётся помеченной за отменённой встречей — так и след виден. */
  $e = expert($b['expert_id']);
  if($e && $e['tg_chat'])
    send($e['tg_chat'], "❌ Запись отменена ($who)\n" . fmtRu($b['dt']) . "\nУченица: " . $b['client_name'] . "\nЧас снова открыт для записи.");
  histAdd($b['id'], 'cancel', 'Отмена (' . $who . ')');
  adminNotify("❌ Отмена ($who): " . $b['client_name'] . ", " . fmtRu($b['dt']) . ", " . ($e['name'] ?? '—'));
  return true;
}

/* ── Очередь сообщений (реле забирает и шлёт в Telegram) ────── */
function send($chat, $text, $kb = null){
  if(!$chat) return false;
  q("INSERT INTO shk_outbox(chat,text,kb,status,created_at) VALUES(?,?,?, 'pending', ?)",
    [$chat, $text, $kb ? json_encode($kb, JSON_UNESCAPED_UNICODE) : null, now()]);
  return true;
}
function adminNotify($text){
  foreach(all("SELECT chat FROM shk_tg WHERE role='admin'") as $r) send($r['chat'], $text);
}
function markSent($bid, $kind, $via = ''){
  q("INSERT IGNORE INTO shk_sent(booking_id,kind,at,via) VALUES(?,?,?,?)", [$bid, $kind, now(), $via]);
}
function wasSent($bid, $kind){
  return (bool)one("SELECT kind FROM shk_sent WHERE booking_id=? AND kind=?", [$bid, $kind]);
}
/* Личная ссылка ученицы на бота. Живёт в карточке, поэтому работает и
   тогда, когда записей ещё нет, и остаётся той же на все будущие. */
/* Личная ссылка куратора или владельца на бота. */
function userTgKey($u){
  if(!$u) return '';
  $k = trim((string)($u['tg_key'] ?? ''));
  if($k === ''){
    $k = bin2hex(random_bytes(6));
    q("UPDATE shk_users SET tg_key=? WHERE id=?", [$k, $u['id']]);
  }
  return $k;
}

function clientTgKey($c){
  if(!$c) return '';
  $k = trim((string)($c['tg_key'] ?? ''));
  if($k === ''){
    $k = bin2hex(random_bytes(6));
    q("UPDATE shk_clients SET tg_key=? WHERE id=?", [$k, $c['id']]);
  }
  return $k;
}

/* Кем человек представлен ученице: «Психолог Ирина», «Сексолог Анна».
   Роль лежит в direction; пусто — говорим просто «специалист». */
function specRole($e){
  $d = trim((string)($e['direction'] ?? ''));
  return $d !== '' ? $d : 'Специалист';
}

function botLink($payload){
  $u = cfg('bot_user');
  return $u ? "https://t.me/$u?start=$payload" : '';
}

function notifyNewBooking($b, $e){
  $txt = "🔔 К вам записалась ученица" . (!empty($b['pair']) ? " · ПАРНАЯ" : "") . "\n" . fmtRu($b['dt']) . "\n\n" .
    "Ученица: " . $b['client_name'] . "\n" .
    (!empty($b['course'])  ? "Курс: " . $b['course'] . "\n" : '') .
    (!empty($b['phone'])   ? "Телефон: " . $b['phone'] . "\n" : '') .
    (!empty($b['tg_nick']) ? "Telegram: @" . $b['tg_nick'] . "\n" : '') .
    (empty($b['phone']) && empty($b['tg_nick']) ? "Связь: " . $b['contact'] . "\n" : '') .
    ($b['category'] !== '' ? "Тема: " . $b['category'] . "\n" : '') .
    ($b['note'] ? "Запрос: «" . $b['note'] . "»\n" : '') . "\n" .
    "Ссылка на встречу: " . ($b['link'] ?: 'пока нет — вставьте её в кабинете, ученице она уйдёт сама');
  tell($e['tg_chat'], $e['email'] ?? '', 'Новая запись — ' . fmtRu($b['dt']), $txt, true);
  adminNotify("🔔 Запись: " . $b['client_name'] . " → " . $e['name'] . ", " . fmtRu($b['dt']) .
              (!empty($b['course']) ? "\nКурс: " . $b['course'] : '') .
              ($b['category'] !== '' ? "\nТема: " . $b['category'] : '') .
              ($b['note'] ? "\nЗапрос: «" . $b['note'] . "»" : '') .
              "\nСвязь: " . $b['contact']);
}

/* ── Телеграм: обработка входящих (логика на сервере) ───────── */
function tgBind($chat, $role, $expertId = null, $bookingId = null, $state = ''){
  q("INSERT INTO shk_tg(chat,role,expert_id,booking_id,state,updated_at) VALUES(?,?,?,?,?,?)
     ON DUPLICATE KEY UPDATE role=VALUES(role), expert_id=VALUES(expert_id),
       booking_id=VALUES(booking_id), state=VALUES(state), updated_at=VALUES(updated_at)",
    [$chat, $role, $expertId, $bookingId, $state, now()]);
}
function tgState($chat){ return one("SELECT * FROM shk_tg WHERE chat=?", [$chat]); }
function setState($chat, $state, $bookingId = null){
  q("UPDATE shk_tg SET state=?, booking_id=COALESCE(?,booking_id), updated_at=? WHERE chat=?",
    [$state, $bookingId, now(), $chat]);
}

function kbRow(...$btns){ return $btns; }
function ikb($rows){ return ['inline_keyboard' => $rows]; }
function btn($text, $data){ return ['text' => $text, 'callback_data' => $data]; }

function clientCard($b){
  $e = expert($b['expert_id']);
  return "✅ Вы записаны\n\n" .
    "🗓 " . fmtRu($b['dt']) . "\n" .
    "👤 " . specRole($e) . ": " . ($e['name'] ?? '—') . "\n" .
    "🔗 Ссылка: " . ($b['link'] ?: 'пришлём отдельно, до встречи') . "\n" .
    "🔑 Код записи: " . $b['code'] . "\n\n" .
    "Напомним за сутки и за два часа. Если время не подходит — перенесите или отмените, слот сразу освободится для другой.";
}
function clientKb($b){
  return ikb([kbRow(btn('Перенести', 'move:' . $b['id']), btn('Отменить запись', 'cancel:' . $b['id']))]);
}
function siteUrl(){
  $u = cfg('site_url');
  return $u ?: (defined('SITE_URL') ? SITE_URL : 'https://booking.example.com') . '/shkola/';
}

function rememberWho($chat, $from){
  if(!$chat || !is_array($from)) return;
  $un = mb_substr((string)($from['username'] ?? ''), 0, 60);
  $fn = trim(mb_substr((string)($from['first_name'] ?? ''), 0, 60) . ' ' . mb_substr((string)($from['last_name'] ?? ''), 0, 60));
  q("UPDATE shk_tg SET uname=?, fname=? WHERE chat=?", [$un, $fn, $chat]);
}
function handleUpdate($u){
  initTables();
  $dur = (int)cfg('duration');

  /* --- нажатие кнопки --- */
  if(isset($u['callback_query'])){
    $cq   = $u['callback_query'];
    $chat = $cq['message']['chat']['id'] ?? 0;
    rememberWho($chat, $cq['from'] ?? null);
    $data = $cq['data'] ?? '';
    if(!$chat) return;
    $p = explode(':', $data);

    if($p[0] === 'csat' && isset($p[1], $p[2])){
      $b = one("SELECT * FROM shk_bookings WHERE id=?", [(int)$p[1]]);
      if($b){
        q("UPDATE shk_bookings SET csat=?, csat_at=? WHERE id=?", [(int)$p[2], now(), $b['id']]);
        setState($chat, 'fit', $b['id']);
        send($chat, "Спасибо! Оценка: " . (int)$p[2] . " из 5.\n\nСпециалист вам подошёл?", ikb([
          kbRow(btn('Да', 'fit:' . $b['id'] . ':Да'), btn('Скорее да', 'fit:' . $b['id'] . ':Скорее да')),
          kbRow(btn('Скорее нет', 'fit:' . $b['id'] . ':Скорее нет'), btn('Нет', 'fit:' . $b['id'] . ':Нет')),
        ]));
        if((int)$p[2] <= 3){
          $e = expert($b['expert_id']);
          adminNotify("⚠️ Низкая оценка " . (int)$p[2] . "/5\n" . $b['client_name'] . " → " . ($e['name'] ?? '—') .
                      ", " . fmtRu($b['dt']) . "\nСтоит позвонить ученице сегодня.");
        }
      }
      return;
    }
    if($p[0] === 'fit' && isset($p[1], $p[2])){
      $b = one("SELECT * FROM shk_bookings WHERE id=?", [(int)$p[1]]);
      if($b){
        q("UPDATE shk_bookings SET fit=? WHERE id=?", [$p[2], $b['id']]);
        setState($chat, 'nps', $b['id']);
        $rows = [];
        for($i = 0; $i <= 10; $i += 4){
          $r = [];
          for($j = $i; $j < min($i + 4, 11); $j++) $r[] = btn((string)$j, 'nps:' . $b['id'] . ':' . $j);
          $rows[] = $r;
        }
        send($chat, "Посоветовали бы вы встречи с специалистом другим ученицам? 0 — точно нет, 10 — точно да.", ikb($rows));
      }
      return;
    }
    if($p[0] === 'nps' && isset($p[1], $p[2])){
      $b = one("SELECT * FROM shk_bookings WHERE id=?", [(int)$p[1]]);
      if($b){
        q("UPDATE shk_bookings SET nps=? WHERE id=?", [(int)$p[2], $b['id']]);
        setState($chat, 'comment', $b['id']);
        send($chat, "Последнее: напишите пару слов своими словами — что было полезно, чего не хватило.\nЕсли добавить нечего, отправьте «-».");
      }
      return;
    }
    if($p[0] === 'cancel' && isset($p[1])){
      $b = one("SELECT * FROM shk_bookings WHERE id=?", [(int)$p[1]]);
      if($b && $b['status'] !== 'cancelled'){
        send($chat, "Точно отменить запись на " . fmtRu($b['dt']) . "?", ikb([
          kbRow(btn('Да, отменить', 'cancelyes:' . $b['id']), btn('Нет, оставить', 'cancelno:' . $b['id'])),
        ]));
      }
      return;
    }
    if($p[0] === 'move' && isset($p[1])){
      $b = one("SELECT * FROM shk_bookings WHERE id=?", [(int)$p[1]]);
      if($b && $b['status'] !== 'cancelled'){
        send($chat, "Перенести встречу можно в вашем кабинете — там видно всё свободное время:\n\n" .
          siteUrl() . "?code=" . $b['code'] . "\n\nСтарое время держим за вами, пока вы не выберете новое.");
      }
      return;
    }
    if($p[0] === 'cancelno'){ send($chat, "Хорошо, запись в силе. До встречи!"); return; }
    if($p[0] === 'cancelyes' && isset($p[1])){
      $b = one("SELECT * FROM shk_bookings WHERE id=?", [(int)$p[1]]);
      if($b && cancelBooking($b, 'ученица')) send($chat, "Запись отменена. Захотите вернуться — выберите новое время в своём кабинете: " . siteUrl());
      return;
    }
    if($p[0] === 'conf' && isset($p[1], $p[2])){
      $b = one("SELECT * FROM shk_bookings WHERE id=?", [(int)$p[1]]);
      if($b){
        $val = $p[2] === 'yes' ? 'yes' : 'no';
        q("UPDATE shk_bookings SET confirm=?, confirm_at=? WHERE id=?", [$val, now(), $b['id']]);
        $e = expert($b['expert_id']);
        histAdd($b['id'], 'confirm', $val === 'yes'
          ? 'Специалист подтвердил: ученица придёт'
          : 'Специалист не дозвонился до ученицы');
        if($val === 'yes') send($chat, "Спасибо, отметила. До встречи " . fmtRu($b['dt']) . ".");
        else{
          send($chat, "Отметила: не отвечает. Координатор подключится — попробует достать её по другим каналам.");
          adminNotify("📵 Ученица не отвечает\n" . $b['client_name'] . ", " . fmtRu($b['dt']) .
            "\nСпециалист: " . ($e['name'] ?? '—') .
            (!empty($b['phone']) ? "\nТелефон: " . $b['phone'] : '') .
            (!empty($b['tg_nick']) ? "\nTelegram: @" . $b['tg_nick'] : '') .
            "\nСтоит попробовать связаться самой — иначе это будет неявка.");
        }
      }
      return;
    }
    /* эксперт отмечает исход встречи */
    if($p[0] === 'held' && isset($p[1], $p[2])){
      $b = one("SELECT * FROM shk_bookings WHERE id=?", [(int)$p[1]]);
      if($b){
        $map = ['h' => 'held', 'n' => 'noshow', 'c' => 'cancelled'];
        $st  = $map[$p[2]] ?? 'held';
        q("UPDATE shk_bookings SET status=? WHERE id=?", [$st, $b['id']]);
        $e   = expert($b['expert_id']);
        $txt = ['held' => 'состоялась', 'noshow' => 'ученица не пришла', 'cancelled' => 'отменена'][$st];
        /* В штате отчёты не обязательны: отметка — и всё, спасибо. */
        send($chat, staffMode()
          ? "Отмечено: $txt. Спасибо!" . ($st === 'held' ? " Если хочется, пару слов о встрече можно дописать в кабинете." : '')
          : "Отмечено: $txt.\n\nОсталось сдать короткий отчёт в кабинете — без него система не даёт новых клиентов.");
        if($st === 'noshow') adminNotify("🚫 Не пришла: " . $b['client_name'] . " → " . ($e['name'] ?? '—') . ", " . fmtRu($b['dt']));
      }
      return;
    }
    return;
  }

  /* --- обычное сообщение --- */
  $m    = $u['message'] ?? null;
  if(!$m) return;
  $chat = $m['chat']['id'] ?? 0;
  $text = trim((string)($m['text'] ?? ''));
  if(!$chat) return;
  rememberWho($chat, $m['from'] ?? null);

  /* Ключ доступа, присланный сообщением. Спасает, когда человек открыл бота
     поиском, а не по ссылке: тогда Telegram не передаёт «хвост» ссылки. */
  $key = ltrim($text, 'aeAE');
  if($text !== '' && mb_strlen($text) >= 8 && mb_strlen($text) <= 40 && strpos($text, ' ') === false){
    foreach([$text, $key] as $try){
      if($try === cfg('admin_key')){
        tgBind($chat, 'admin');
        rememberWho($chat, $m['from'] ?? null);
        send($chat, "✅ Готово, вы подключены как куратор.\n\nСюда пойдут все новые записи, отмены, неявки и оценки ниже 4.");
        return;
      }
      $ex = one("SELECT * FROM shk_experts WHERE tg_key=?", [$try]);
      if($ex){
        q("UPDATE shk_experts SET tg_chat=? WHERE id=?", [$chat, $ex['id']]);
        tgBind($chat, 'expert', $ex['id']);
        rememberWho($chat, $m['from'] ?? null);
        send($chat, "✅ Готово, " . $ex['name'] . ". Кабинет подключён — сюда пойдут новые записи и напоминания.");
        return;
      }
    }
  }

  if(strpos($text, '/start') === 0){
    $payload = trim(substr($text, 6));
    /* лист ожидания: w<КОД> */
    if($payload && ($payload[0] === 'w' || $payload[0] === 'W')){
      $code = strtoupper(substr($payload, 1));
      $w = one("SELECT * FROM shk_wait WHERE code=?", [$code]);
      if($w){
        q("UPDATE shk_wait SET tg_chat=? WHERE id=?", [$chat, $w['id']]);
        send($chat, "Готово — вы в очереди на консультацию.\n\n" .
          ($w['topic'] !== '' ? "Тема: " . $w['topic'] . "\n" : '') .
          "Как только специалист откроет подходящее время, напишем сюда. Записываться нужно будет самой: время достаётся той, кто успела первой.");
        return;
      }
      send($chat, "Не нашли заявку по этому коду. Оставьте её заново на странице записи.");
      return;
    }
    /* клиент: b<КОД> */
    if($payload && ($payload[0] === 'b' || $payload[0] === 'B')){
      $code = strtoupper(substr($payload, 1));
      $b = one("SELECT * FROM shk_bookings WHERE code=?", [$code]);
      if($b){
        q("UPDATE shk_bookings SET tg_chat=? WHERE id=?", [$chat, $b['id']]);
        /* И на карточку ученицы — чтобы следующая её запись сразу знала,
           куда писать, и бота не пришлось подключать заново. */
        if(!empty($b['ckey'])){
          q("UPDATE shk_clients SET tg_chat=? WHERE ckey=?", [$chat, $b['ckey']]);
          q("UPDATE shk_bookings SET tg_chat=? WHERE ckey=? AND dt>? AND COALESCE(tg_chat,0)=0",
            [$chat, $b['ckey'], now()]);
        }
        /* координатора или специалиста не понижаем до клиента — иначе,
           проверив клиентскую ссылку со своего телефона, человек потеряет свою роль */
        $cur = tgState($chat);
        if($cur && in_array($cur['role'], ['admin', 'expert'], true))
          q("UPDATE shk_tg SET booking_id=?, updated_at=? WHERE chat=?", [$b['id'], now(), $chat]);
        else
          tgBind($chat, 'client', null, $b['id']);
        send($chat, clientCard($b), clientKb($b));
        return;
      }
      send($chat, "Не нашли запись по этому коду. Проверьте код из подтверждения или запишитесь заново на странице записи.");
      return;
    }
    /* ученица по личной ссылке: c<ключ>. Работает и до первой записи. */
    if($payload && ($payload[0] === 'c' || $payload[0] === 'C')){
      $key = substr($payload, 1);
      $cl = $key !== '' ? one("SELECT * FROM shk_clients WHERE tg_key=?", [$key]) : null;
      if($cl){
        q("UPDATE shk_clients SET tg_chat=? WHERE id=?", [$chat, $cl['id']]);
        q("UPDATE shk_bookings SET tg_chat=? WHERE ckey=? AND dt>?", [$chat, $cl['ckey'], now()]);
        $cur = tgState($chat);
        $b = one("SELECT * FROM shk_bookings WHERE ckey=? AND dt>? AND status<>'cancelled' ORDER BY dt LIMIT 1",
                 [$cl['ckey'], now()]);
        if(!$cur || !in_array($cur['role'], ['admin', 'expert'], true))
          tgBind($chat, 'client', null, $b['id'] ?? null);
        if($b) send($chat, "Здравствуйте, " . $cl['name'] . "! Бот подключён — напоминания придут сюда.\n\n" . clientCard($b), clientKb($b));
        else   send($chat, "Здравствуйте, " . $cl['name'] . "! Бот подключён.\n\n" .
                    "Записи пока нет. Как запишетесь — пришлю сюда время, ссылку на встречу и напоминания.\n" . siteUrl());
        return;
      }
      send($chat, "Ссылка не подошла. Откройте её заново в своём кабинете, в разделе «Профиль».");
      return;
    }
    /* эксперт: e<ключ> */
    if($payload && ($payload[0] === 'e' || $payload[0] === 'E')){
      $key = substr($payload, 1);
      $e = one("SELECT * FROM shk_experts WHERE tg_key=?", [$key]);
      if($e){
        q("UPDATE shk_experts SET tg_chat=? WHERE id=?", [$chat, $e['id']]);
        tgBind($chat, 'expert', $e['id']);
        send($chat, "Здравствуйте, " . $e['name'] . "! Кабинет подключён.\n\n" .
          "Сюда будут приходить:\n• новая запись — сразу\n• накануне — просьба связаться с ученицей\n" .
          "• напоминание за 2 часа\n• после встречи — вопрос, состоялась ли она\n\n" .
          "Открыть время и отметить встречи — в вашем кабинете на сайте.");
        return;
      }
      send($chat, "Ключ не подошёл. Возьмите свежую ссылку у координатора.");
      return;
    }
    /* куратор или владелец по личной ссылке: u<ключ> */
    if($payload && ($payload[0] === 'u' || $payload[0] === 'U')){
      $key = substr($payload, 1);
      $u = $key !== '' ? one("SELECT * FROM shk_users WHERE tg_key=? AND status='active'", [$key]) : null;
      if($u && in_array($u['role'], ['admin', 'owner'], true)){
        q("UPDATE shk_users SET tg_chat=? WHERE id=?", [$chat, $u['id']]);
        tgBind($chat, 'admin');
        q("UPDATE shk_tg SET user_id=? WHERE chat=?", [(int)$u['id'], $chat]);
        send($chat, "Здравствуйте, " . $u['name'] . "! Вы подключены как " .
          ($u['role'] === 'owner' ? 'владелец' : 'куратор') . ".\n\n" .
          "Сюда будут приходить:\n" .
          "• новые записи и отмены\n" .
          "• когда ученица не смогла записаться — сразу, с причиной\n" .
          "• неявки и оценки ниже 4\n" .
          "• если очередь сообщений встала\n\n" .
          "Подтверждать заявки и вести учениц — в кабинете: " . siteUrl());
        return;
      }
      send($chat, "Ссылка не подошла. Возьмите свежую в своём кабинете, раздел «Профиль».");
      return;
    }
    /* координатор: a<ключ> */
    if($payload && ($payload[0] === 'a' || $payload[0] === 'A')){
      if(substr($payload, 1) === cfg('admin_key')){
        tgBind($chat, 'admin');
        send($chat, "Координатор подключён. Сюда пойдут: новые записи, отмены, неявки и все оценки ниже 4.");
        return;
      }
      send($chat, "Ключ не подошёл.");
      return;
    }
    send($chat, "Это бот школы — напоминания о встречах со специалистом.\n\n" .
      "Подключаются все одинаково — и ученицы, и специалисты, и кураторы: зайдите в свой кабинет " .
      "на сайте, раздел «Профиль», и нажмите «Подключить за один тап».\n" . siteUrl() . "\n\n" .
      "Ссылку нужно открыть именно оттуда — так бот поймёт, кто вы. Коды входа на сайт сюда " .
      "присылать не нужно: это разные вещи.");
    return;
  }

  /* свободный комментарий в конце опроса */
  $st = tgState($chat);
  if($st && $st['state'] === 'comment' && $st['booking_id']){
    $c = ($text === '-' || $text === '—') ? '' : mb_substr($text, 0, 900);
    q("UPDATE shk_bookings SET comment=? WHERE id=?", [$c, $st['booking_id']]);
    setState($chat, '');
    send($chat, "Спасибо! Отзыв записан — он попадает координатору сразу.");
    if($c !== ''){
      $b = one("SELECT * FROM shk_bookings WHERE id=?", [$st['booking_id']]);
      $e = expert($b['expert_id']);
      adminNotify("💬 Отзыв (" . ($b['csat'] ?: '—') . "/5, NPS " . ($b['nps'] ?? '—') . ")\n" .
                  $b['client_name'] . " → " . ($e['name'] ?? '—') . "\n«" . $c . "»");
    }
    return;
  }

  if($st && $st['role'] === 'expert'){
    send($chat, "Открыть время и отметить встречи — в вашем кабинете на сайте. Сюда я присылаю только уведомления.");
    return;
  }
  /* Сюда попадает всё непонятое — в том числе коды входа на сайт, которые
     люди по привычке шлют боту. Объясняем разницу, а не отмахиваемся. */
  send($chat, "Не поняла вас. Если вы присылали код входа на сайт — для бота он не подходит, это разные вещи.\n\n" .
    "Чтобы подключиться, зайдите в свой кабинет, раздел «Профиль», и нажмите «Подключить за один тап»:\n" . siteUrl() . "\n\n" .
    "Всё остальное — записи, переносы, отметки — делается в кабинете. Сюда я только присылаю уведомления.");
}

/* ── Планировщик: напоминания и опросы ──────────────────────── */
function tick(){
  initTables();
  settings();
  $dur  = (int)cfg('duration');
  $nowT = time();
  $rows = all("SELECT * FROM shk_bookings WHERE status IN ('booked','held','noshow','searching')
                 AND dt > ? AND dt < ?",
              [date('Y-m-d H:i:s', $nowT - 5*86400), date('Y-m-d H:i:s', $nowT + 8*86400)]);
  foreach($rows as $b){
    $t = ts($b['dt']);
    $e = expert($b['expert_id']);
    if(!$e) continue;
    $end = $t + $dur * 60;

    /* за сутки — клиенту */
    if(cfg('remind24') === '1' && $b['status'] === 'booked' && hasChannel($b)
       && $nowT >= $t - 24*3600 && $nowT < $t && !wasSent($b['id'], 'r24')){
      $txt = "⏰ Завтра встреча\n" . fmtRu($b['dt']) . "\n" . specRole($e) . ": " . $e['name'] .
        "\nСсылка: " . ($b['link'] ?: 'пришлём отдельно') .
        "\n\nЕсли не сможете — перенесите или отмените, чтобы место досталось другой: " . siteUrl() . "?code=" . $b['code'];
      $via = '';
      if($b['tg_chat'] && tgAlive()){ send($b['tg_chat'], $txt, clientKb($b)); $via = 'tg'; }
      elseif(smsOn() && smsSend(bookingPhone($b), 'Завтра в ' . date('H:i', $t) .
              ' встреча. Перенос: ' . smsUrl($b))) $via = 'sms';
      elseif($b['email'] && sendMail($b['email'], 'Завтра встреча — ' . fmtRu($b['dt']), $txt)) $via = 'mail';
      elseif($b['tg_chat']){ send($b['tg_chat'], $txt, clientKb($b)); $via = 'tg'; }
      markSent($b['id'], 'r24', $via ?: 'none');
    }
    /* за два часа — клиенту */
    if(cfg('remind2') === '1' && $b['status'] === 'booked' && hasChannel($b)
       && $nowT >= $t - 2*3600 && $nowT < $t && !wasSent($b['id'], 'r2')){
      $txt = "⏰ Через 2 часа — ваша встреча\n" . fmtRu($b['dt']) .
        "\nСсылка: " . ($b['link'] ?: 'уточняется') . "\n\nНайдите тихое место и наушники — так разговор получается глубже.";
      $via = tell($b['tg_chat'], $b['email'], 'Через 2 часа встреча', $txt, true,
        bookingPhone($b), 'Через 2 часа встреча. Ссылка: ' . smsUrl($b));
      markSent($b['id'], 'r2', $via ?: 'none');
    }
    /* за 15 минут — клиентке, последний толчок перед встречей */
    if(cfg('remind15') === '1' && $b['status'] === 'booked'
       && $nowT >= $t - 15*60 && $nowT < $t && !wasSent($b['id'], 'r15')){
      $short = 'Через 15 мин. встреча. Ссылка: ' . smsUrl($b);
      $via = '';
      if($b['tg_chat'] && tgAlive()){ send($b['tg_chat'], "⏰ Через 15 минут встреча\nСсылка: " . ($b['link'] ?: codeUrl($b))); $via = 'tg'; }
      elseif(smsOn() && smsSend(bookingPhone($b), $short)) $via = 'sms';
      elseif($b['email'] && sendMail($b['email'], 'Встреча через 15 минут', $short)) $via = 'mail';
      elseif($b['tg_chat']){ send($b['tg_chat'], $short); $via = 'tg'; }
      markSent($b['id'], 'r15', $via ?: 'none');
    }

    /* накануне — эксперту: связаться с клиентом и подтвердить приход */
    if($b['status'] === 'booked' && ($e['tg_chat'] || !empty($e['email'])) && $b['confirm'] === ''
       && $nowT >= $t - 20*3600 && $nowT < $t - 2*3600 && !wasSent($b['id'], 'econf')){
      $txt = "📞 Свяжитесь с ученицей\n" . fmtRu($b['dt']) . (!empty($b['pair']) ? " · ПАРНАЯ" : "") .
        "\n\nУченица: " . $b['client_name'] .
        (!empty($b['phone'])   ? "\nТелефон: " . $b['phone'] : '') .
        (!empty($b['tg_nick']) ? "\nTelegram: @" . $b['tg_nick'] : '') .
        ($b['category'] !== '' ? "\nТема: " . $b['category'] : '') . ($b['note'] ? "\nЗапрос: «" . $b['note'] . "»" : '') .
        "\n\nНапишите или позвоните заранее и подтвердите, что она придёт. " .
        "Так доходимость выше, а если не сможет — время успеет уйти другой ученице.";
      if($e['tg_chat']) send($e['tg_chat'], $txt, ikb([
        kbRow(btn('Ученица подтвердила', 'conf:' . $b['id'] . ':yes')),
        kbRow(btn('Не отвечает', 'conf:' . $b['id'] . ':no')),
      ]));
      else sendMail($e['email'], 'Свяжитесь с ученицей — ' . fmtRu($b['dt']), $txt);
      markSent($b['id'], 'econf', $e['tg_chat'] ? 'tg' : 'mail');
    }

    /* за два часа — эксперту */
    if($b['status'] === 'booked' && ($e['tg_chat'] || !empty($e['email']))
       && $nowT >= $t - 2*3600 && $nowT < $t && !wasSent($b['id'], 'e2')){
      $txt = "⏰ Через 2 часа встреча" . (!empty($b['pair']) ? " · ПАРНАЯ" : "") . "\n" . fmtRu($b['dt']) . "\nУченица: " . $b['client_name'] .
        ($b['category'] !== '' ? "\nТема: " . $b['category'] : '') . ($b['note'] ? "\nЗапрос: «" . $b['note'] . "»" : '') .
        "\nСвязь: " . $b['contact'] . "\nСсылка: " . ($b['link'] ?: '—');
      markSent($b['id'], 'e2', tell($e['tg_chat'], $e['email'] ?? '', 'Через 2 часа встреча', $txt, true) ?: 'none');
    }
    /* после встречи — эксперту вопрос об исходе */
    if($b['status'] === 'booked' && $e['tg_chat'] && $nowT >= $end + 5*60 && !wasSent($b['id'], 'eask')){
      send($e['tg_chat'], "Встреча " . fmtRu($b['dt']) . " с " . $b['client_name'] . " — как прошла?", ikb([
        kbRow(btn('Провели', 'held:' . $b['id'] . ':h')),
        kbRow(btn('Ученица не пришла', 'held:' . $b['id'] . ':n'), btn('Отменили заранее', 'held:' . $b['id'] . ':c')),
      ]));
      markSent($b['id'], 'eask');
    }
    /* после встречи — клиенту опрос */
    if(cfg('ask_csat') === '1' && !$b['tg_chat'] && $b['email'] && $b['status'] !== 'noshow' && $b['status'] !== 'cancelled'
       && !$b['csat'] && $nowT >= $end + 15*60 && $nowT < $end + 48*3600 && !wasSent($b['id'], 'ask')){
      sendMail($b['email'], 'Как прошла встреча?',
        "Здравствуйте, " . $b['client_name'] . "!\n\nОцените встречу с " . $e['name'] . " — это две минуты:\n" .
        siteUrl() . "?code=" . $b['code'] . "\n\nВаш отзыв видит только координатор.");
      markSent($b['id'], 'ask');
    }
    if(cfg('ask_csat') === '1' && $b['tg_chat'] && $b['status'] !== 'noshow' && $b['status'] !== 'cancelled'
       && !$b['csat'] && $nowT >= $end + 15*60 && $nowT < $end + 48*3600 && !wasSent($b['id'], 'ask')){
      send($b['tg_chat'], "Как прошла встреча с " . $e['name'] . "? Оцените от 1 до 5 — это две минуты, а нам помогает держать качество.", ikb([
        kbRow(btn('1', 'csat:' . $b['id'] . ':1'), btn('2', 'csat:' . $b['id'] . ':2'), btn('3', 'csat:' . $b['id'] . ':3'),
              btn('4', 'csat:' . $b['id'] . ':4'), btn('5', 'csat:' . $b['id'] . ':5')),
      ]));
      markSent($b['id'], 'ask');
    }
    /* запись висит без специалиста, а встреча уже близко */
    if($b['status'] === 'searching' && $nowT >= $t - 24*3600 && $nowT < $t && !wasSent($b['id'], 'nospec')){
      adminNotify("❗️ Запись без специалиста\n" . $b['client_name'] . ", " . fmtRu($b['dt']) .
        "\nВстреча меньше чем через сутки, а специалист не назначен. Ученица ничего не знает и придёт.");
      markSent($b['id'], 'nospec', 'admin');
    }

    /* отчёт не сдан через 3 часа */
    if(!staffMode() && $b['status'] === 'held' && !$b['rep_at'] && $e['tg_chat']
       && $nowT >= $end + 3*3600 && !wasSent($b['id'], 'rep')){
      send($e['tg_chat'], "📝 Отчёт по встрече " . fmtRu($b['dt']) . " (" . $b['client_name'] . ") ещё не сдан.\n" .
        "Две минуты в кабинете — и клиент остаётся в системе.");
      markSent($b['id'], 'rep');
      adminNotify("📝 Нет отчёта: " . $e['name'] . " — " . $b['client_name'] . ", " . fmtRu($b['dt']));
    }
  }
  hookRetry();
  checkQueueStall();
  svodkaKuratoram();
  dailyCare();
  weeklyNudge();
  eveningPlan();
  setSetting('last_tick', now());
}

/* ── Тревога: очередь стоит, значит реле не работает ─────────
   Telegram в этот момент как раз недоступен, поэтому зовём по почте. */
/* Почта — второй канал: для тех, кто не подключил Telegram */
function sendMail($to, $subject, $body){
  $to = trim((string)$to);
  if($to === '' || !filter_var($to, FILTER_VALIDATE_EMAIL)) return false;
  $host = $_SERVER['HTTP_HOST'] ?? 'booking.example.com';
  $from = 'zapis@' . preg_replace('/[^a-z0-9.\-]/i', '', $host);
  $h = "From: Запись на консультацию <$from>\r\n"
     . "Content-Type: text/plain; charset=utf-8\r\n"
     . "X-Mailer: shkola-zapis\r\n";
  $okSent = @mail($to, '=?UTF-8?B?' . base64_encode($subject) . '?=', $body, $h);
  logit('mail', ($okSent ? 'ok ' : 'fail ') . $to . ' — ' . $subject);
  return $okSent;
}
function alertMail($subject, $body){ return sendMail(cfg('alert_email'), $subject, $body); }

/* Реле живо, если выходило на связь последние 15 минут.
   Если нет (компьютер уснул) — срочное лучше отправить почтой, чем ждать. */
/* Есть ли вообще чем достучаться до клиентки */
function hasChannel($b){
  return $b['tg_chat'] || $b['email'] || (smsOn() && bookingPhone($b) !== '');
}
function tgAlive(){
  $t = cfg('last_relay');
  return $t !== '' && ts($t) > time() - 900;
}
/* ── SMS через SMS Aero ──────────────────────────────────────
   Третий канал: для тех, кто оставил телефон, но не подключил бота. */
function smsOn(){ return cfg('sms_on') === '1' && cfg('sms_email') !== '' && cfg('sms_key') !== ''; }
function phoneOf($contact){
  $d = preg_replace('/\D/', '', (string)$contact);
  if(strlen($d) === 11 && ($d[0] === '8' || $d[0] === '7')) return '7' . substr($d, 1);
  if(strlen($d) === 10) return '7' . $d;
  return '';
}
function smsApi($path, $params = []){
  $url = 'https://gate.smsaero.ru/v2' . $path . (
    $params ? '?' . http_build_query($params) : '');
  $c = curl_init($url);
  curl_setopt_array($c, [
    CURLOPT_RETURNTRANSFER => 1, CURLOPT_TIMEOUT => 20,
    CURLOPT_USERPWD => cfg('sms_email') . ':' . cfg('sms_key'),
    CURLOPT_HTTPHEADER => ['Accept: application/json'],
  ]);
  $r = curl_exec($c); $err = curl_error($c); $code = curl_getinfo($c, CURLINFO_HTTP_CODE); curl_close($c);
  if($r === false) throw new Exception('SMS Aero недоступен: ' . $err);
  $j = json_decode($r, true);
  if(!is_array($j)) throw new Exception('SMS Aero ответил непонятно (' . $code . ')');
  if(empty($j['success'])) throw new Exception(mb_substr((string)($j['message'] ?? 'отказ'), 0, 160));
  return $j;
}
function bookingPhone($b){
  $p = phoneOf($b['phone'] ?? '');
  return $p !== '' ? $p : phoneOf($b['contact'] ?? '');
}
function smsSend($contact, $text){
  if(!smsOn()) return false;
  $phone = phoneOf($contact);
  if($phone === '') return false;
  try{
    smsApi('/sms/send', [
      'number'  => $phone,
      'text'    => mb_substr($text, 0, 300),
      'sign'    => cfg('sms_sign') ?: 'SMS Aero',
      'channel' => 'DIRECT',
    ]);
    logit('sms', 'ok ' . $phone . ' — ' . mb_substr($text, 0, 60));
    return true;
  }catch(Throwable $e){
    logit('sms', 'fail ' . $phone . ' — ' . $e->getMessage());
    return false;
  }
}
/* Короткая ссылка на страницу записи: там и ссылка на встречу, и перенос, и отмена */
function codeUrl($b){ return siteUrl() . '?code=' . $b['code']; }
/* Для SMS — без https://, чтобы влезать в одно сообщение. Телефоны всё равно делают её ссылкой. */
function smsUrl($b){ return preg_replace('~^https?://~', '', siteUrl()) . '?code=' . $b['code']; }
/* Сколько частей займёт SMS: кириллица — 70 символов на одну, дальше по 67 */
function smsParts($text){
  $n = mb_strlen($text);
  return $n <= 70 ? 1 : (int)ceil($n / 67);
}

/* Одно сообщение — в Telegram, если подключён и доставка работает,
   иначе на почту. Дублировать не надо: два одинаковых напоминания раздражают. */
function tell($chat, $email, $subject, $text, $urgent = false, $smsTo = '', $smsText = ''){
  if($chat && (!$urgent || tgAlive())){ send($chat, $text); return 'tg'; }
  if($smsTo !== '' && $smsText !== '' && smsSend($smsTo, $smsText)) return 'sms';
  if($email && sendMail($email, $subject, $text)) return 'mail';
  if($chat){ send($chat, $text); return 'tg'; }   /* больше нечем — пусть ждёт в очереди */
  return '';
}
function meetingCard($b, $e){
  return "Встреча: " . fmtRu($b['dt']) . "\n" .
         specRole($e) . ": " . ($e['name'] ?? '—') . "\n" .
         "Ссылка: " . ($b['link'] ?: 'пришлём отдельно') . "\n" .
         "Код записи: " . $b['code'];
}
/* Раз в сутки смотрим за психологами: не кончились ли ссылки и открыто ли
   время вперёд. Без этого обе беды всплывают в худший момент — когда
   куратор уже подтверждает заявку или когда ученице некуда записаться. */
/* Специалист отменяет свою встречу: заболела, накладка. Раньше он мог
   только написать куратору, а ученица сидела и ждала. Слот освобождается,
   ученице уходит письмо с приглашением выбрать другое время. */
function expertCancel($b, $why){
  if(!$b || $b['status'] === 'cancelled') return false;
  $e = expert($b['expert_id']);
  cancelBooking($b, 'специалист' . ($why !== '' ? ': ' . $why : ''));
  if(!empty($b['email']))
    sendMail($b['email'], 'Встреча отменена — ' . fmtRu($b['dt']),
      "Здравствуйте, " . $b['client_name'] . "!\n\n" .
      "К сожалению, встреча " . fmtRu($b['dt']) . " не состоится" .
      ($why !== '' ? " — " . $why : '') . ".\n\n" .
      "Выберите другое удобное время: " . siteUrl() . "\n" .
      "Консультация при этом не сгорает — она остаётся за вами.\n\n" .
      "Если что-то непонятно, напишите куратору.");
  if(!empty($b['tg_chat']))
    send($b['tg_chat'], "Встреча " . fmtRu($b['dt']) . " отменена" . ($why !== '' ? " — " . $why : '') .
      ".\nВыбрать другое время: " . siteUrl());
  return true;
}

/* Вечером — список завтрашних встреч специалисту. Ученицам напоминания
   уходят, а специалист до сих пор узнавал о дне только зайдя в кабинет. */
/* Утром и вечером — короткая сводка владельцу и кураторам в бот.
   Утром: что сегодня и что ждёт решения. Вечером: чем день кончился.
   Раньше это можно было узнать, только открыв дашборд. */
function svodkaKuratoram(){
  $chas = (int)date('G');
  $den  = date('Y-m-d');

  /* утро: 8:00–11:59, один раз в день */
  if($chas >= 8 && $chas < 12 && cfg('svodka_utro') !== $den){
    setSetting('svodka_utro', $den);
    $s = svodkaDni(0);
    $t = "☀️ " . fmtData($den) . ", план на день\n\n";
    if(!$s['segodnya']) $t .= "Встреч сегодня нет.\n";
    else{
      $t .= "Встреч сегодня: " . count($s['segodnya']) . "\n";
      foreach($s['segodnya'] as $b)
        $t .= '· ' . date('H:i', ts($b['dt'])) . ' — ' . $b['name'] .
              ($b['ename'] ? ' → ' . $b['ename'] : '') .
              ($b['link'] ? '' : '  ⚠️ без ссылки') . "\n";
    }
    $hvost = [];
    if($s['zayavki'])    $hvost[] = "заявок ждёт подтверждения: " . $s['zayavki'];
    if($s['bezOtmetki']) $hvost[] = "встреч без отметки: " . $s['bezOtmetki'];
    $zhdut = (int)one("SELECT COUNT(*) c FROM shk_users WHERE status='pending'")['c'];
    if($zhdut) $hvost[] = "сотрудников ждут доступа: " . $zhdut;
    $svobodno = (int)one("SELECT COUNT(*) c FROM shk_slots s JOIN shk_experts e ON e.id=s.expert_id
                           WHERE s.booking_id IS NULL AND e.active=1 AND s.dt>?", [now()])['c'];
    $hvost[] = "свободных часов впереди: " . $svobodno;
    if($hvost) $t .= "\n" . implode("\n", $hvost);
    adminNotify($t);
  }

  /* вечер: с 20:00, один раз в день */
  if($chas >= 20 && cfg('svodka_vecher') !== $den){
    setSetting('svodka_vecher', $den);
    $s = svodkaDni(0);
    if($s['segodnya'] || $s['zavtra']){
      $t = "🌙 Как прошёл день\n\n";
      $t .= "Провели: " . $s['proshlo'] . "\n";
      $ne = 0;
      foreach($s['segodnya'] as $b) if($b['status'] === 'noshow') $ne++;
      if($ne) $t .= "Не пришли: " . $ne . "\n";
      if($s['bezOtmetki']) $t .= "⚠️ Без отметки специалиста: " . $s['bezOtmetki'] . "\n";
      $t .= "\nЗавтра встреч: " . count($s['zavtra']) . "\n";
      foreach($s['zavtra'] as $b)
        $t .= '· ' . date('H:i', ts($b['dt'])) . ' — ' . $b['name'] .
              ($b['ename'] ? ' → ' . $b['ename'] : '') . "\n";
      if($s['zayavki']) $t .= "\nЗаявок ждёт подтверждения: " . $s['zayavki'];
      adminNotify($t);
    }
  }
}

function eveningPlan(){
  if((int)date('G') < 18) return;                 /* рано, ещё день */
  $today = date('Y-m-d');
  if(cfg('plan_at') === $today) return;
  setSetting('plan_at', $today);

  $from = date('Y-m-d 00:00:00', strtotime('+1 day'));
  $to   = date('Y-m-d 23:59:59', strtotime('+1 day'));
  foreach(all("SELECT * FROM shk_experts WHERE active=1") as $e){
    $rows = all("SELECT * FROM shk_bookings WHERE expert_id=? AND status='booked' AND dt>=? AND dt<=? ORDER BY dt",
                [$e['id'], $from, $to]);
    if(!$rows) continue;
    $txt = "Здравствуйте, " . $e['name'] . "!\n\nЗавтра у вас " . count($rows) . ' ' .
           (count($rows) === 1 ? 'встреча' : 'встречи') . ":\n\n";
    foreach($rows as $b){
      $cnt = '';
      if($b['ckey'] !== ''){
        $c = clientFind($b['ckey']);
        if($c){ $a = clientAllowed($c); if($a > 0) $cnt = ' (' . clientUsed($b['ckey']) . ' из ' . $a . ')'; }
      }
      $txt .= date('H:i', ts($b['dt'])) . ' — ' . $b['client_name'] . $cnt . "\n" .
              ($b['link'] !== '' ? '   ' . $b['link'] . "\n" : "   ссылки пока нет\n") .
              ($b['adm_note'] !== '' ? '   от куратора: ' . $b['adm_note'] . "\n" : '');
    }
    $txt .= "\nКабинет: " . siteUrl() . "spec/";
    if(trim((string)$e['email']) !== '') sendMail($e['email'], 'Завтра: ' . count($rows) . ' встреч', $txt);
    if(!empty($e['tg_chat'])) send($e['tg_chat'], $txt);
  }
}

/* Раз в неделю зовём тех, кто не записан. Пишем только тем, у кого есть
   аккаунт: перенесённые из старых таблиц в эту систему не приходили и
   рассылку не заказывали. И молчим, если записаться всё равно нельзя —
   запас кончился, доступ закрыт или ещё не прошла пауза. */
function weeklyNudge(){
  if(cfg('nudge') !== '1') return;
  $last = cfg('nudge_at');
  if($last && ts($last) > time() - 20*3600) return;
  setSetting('nudge_at', now());

  $week = date('Y-m-d H:i:s', time() - 7*86400);
  $sent = 0;
  foreach(all("SELECT * FROM shk_users WHERE role='client' AND status='active'
                 AND (nudged_at IS NULL OR nudged_at < ?)", [$week]) as $u){
    /* Карточка могла ещё не появиться — тот, кто ни разу не записывался,
       как раз и есть адресат этого письма. */
    $c = clientForUser($u);
    if(!$c || (int)$c['blocked'] === 1) continue;

    /* уже записана — звать незачем */
    $ahead = one("SELECT id FROM shk_bookings WHERE ckey=? AND status IN ('pending','booked') AND dt > ?",
                 [$c['ckey'], now()]);
    if($ahead) continue;

    /* запас кончился — звать некуда */
    $allowed = clientAllowed($c);
    if($allowed > 0 && clientUsed($c['ckey']) >= $allowed) continue;

    /* пауза ещё не вышла — придёт и упрётся */
    $d = cooldownDays();
    if($d > 0){
      $recent = one("SELECT dt FROM shk_bookings WHERE ckey=? AND status<>'cancelled' AND dt > ?
                     ORDER BY dt DESC LIMIT 1", [$c['ckey'], date('Y-m-d H:i:s', time() - $d*86400)]);
      if($recent) continue;
    }

    $free = count(openTimes());
    $txt = "Здравствуйте, " . $c['name'] . "!\n\n" .
      "Вы давно не были у специалиста. Если есть о чём поговорить — выберите удобное время:\n" .
      siteUrl() . "\n\n" .
      ($free ? "Сейчас открыто свободных часов: " . $free . ".\n" : "Свободного времени пока нет — оставьте заявку, и мы позовём, как только оно появится.\n") .
      ($allowed > 0 ? "Осталось консультаций по курсу: " . max(0, $allowed - clientUsed($c['ckey'])) . ".\n" : '') .
      "\nЕсли сейчас не нужно — просто не отвечайте, мы напомним не раньше чем через неделю.";
    /* Телеграм-чат у ученицы привязан к записи, а не к карточке, поэтому
       зов уходит почтой — она есть у всех, кто завёл аккаунт. */
    if(sendMail($u['email'], 'Ждём вас на встрече с специалистом', $txt)) $sent++;
    q("UPDATE shk_users SET nudged_at=? WHERE id=?", [now(), $u['id']]);
  }
  if($sent) logit('nudge', "позвали на встречу: $sent");
}

function dailyCare(){
  $last = cfg('care_at');
  if($last && ts($last) > time() - 20*3600) return;
  setSetting('care_at', now());

  $norm  = (int)cfg('slot_norm');
  $ahead = date('Y-m-d H:i:s', time() + 14*86400);
  $tired = [];
  foreach(all("SELECT * FROM shk_experts WHERE active=1") as $e){
    $free = linksFree($e['id']);
    $open = (int)one("SELECT COUNT(*) c FROM shk_slots WHERE expert_id=? AND booking_id IS NULL AND dt > ? AND dt < ?",
                     [$e['id'], now(), $ahead])['c'];
    $needLinks = !ownRoom($e) && !zoomOn() && $free < 3;
    $needSlots = $norm > 0 && $open < 5;
    if(!$needLinks && !$needSlots) continue;

    $tired[] = $e['name'] . ($needLinks ? ' — ссылок ' . $free : '') . ($needSlots ? ' — свободных часов ' . $open : '');
    if(trim((string)$e['email']) === '') continue;   /* некуда писать */
    $txt = "Здравствуйте, " . $e['name'] . "!\n\n";
    if($needLinks) $txt .= "В запасе осталось ссылок: " . $free . ". Когда он опустеет, ученица не получит ссылку на встречу.\n" .
                           "Нарежьте новые и вставьте в кабинете: " . siteUrl() . "spec/\n\n";
    if($needSlots) $txt .= "На ближайшие две недели открыто свободных часов: " . $open . ".\n" .
                           "Откройте время в кабинете, иначе ученицам некуда записаться: " . siteUrl() . "spec/\n\n";
    sendMail($e['email'], 'Кабинет специалиста: нужно ваше внимание', $txt);
  }
  if($tired) adminNotify("⚠️ Психологам нужно внимание:\n" . implode("\n", $tired));
}

function checkQueueStall(){
  if(cfg('tg_token') === '') return;                     /* бот и не включали */
  $old = one("SELECT MIN(created_at) m, COUNT(*) c FROM shk_outbox WHERE status='pending'");
  if(!$old || !$old['c'] || !$old['m']) return;
  if(ts($old['m']) > time() - 3600) return;              /* ждём меньше часа — нормально */
  $last = cfg('last_alert');
  if($last && ts($last) > time() - 3*3600) return;       /* не чаще раза в три часа */
  setSetting('last_alert', now());
  $site = cfg('site_url') ?: (defined('SITE_URL') ? SITE_URL : 'https://booking.example.com') . '/shkola/';
  alertMail(
    'Уведомления не уходят: реле не на связи',
    "В очереди застряло сообщений: " . (int)$old['c'] . ", самое старое с " . $old['m'] . ".\n" .
    "Реле последний раз выходило на связь: " . (cfg('last_relay') ?: 'ни разу') . ".\n\n" .
    "Это значит, что ученицы и специалисты сейчас не получают напоминания.\n" .
    "Что сделать: включить компьютер, где стоит реле, — оно поднимется само.\n" .
    "Пока реле не поднято — разошлите напоминания вручную: $site , вкладка «Ручная рассылка».\n\n" .
    "Как только реле поднимется, очередь уйдёт сама, ничего не потеряется."
  );
  logit('alert', 'очередь стоит: ' . (int)$old['c']);
}
/* Дёргаем планировщик попутно, не чаще раза в минуту — работает и без крона */
function tickMaybe(){
  $last = one("SELECT v FROM shk_settings WHERE k='last_tick'");
  if($last && ts($last['v']) > time() - 60) return;
  try{ tick(); }catch(Throwable $e){ logit('tick', $e->getMessage()); }
}
