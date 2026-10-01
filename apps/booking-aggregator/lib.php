<?php
/* ═══════════════════════════════════════════════════════════════
   Агрегатор записи на консультации — общее ядро.
   Данные: MySQL (таблицы psy_*), та же база, что у «Трекера».
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
  db()->exec("CREATE TABLE IF NOT EXISTS psy_settings(
    k VARCHAR(40) PRIMARY KEY, v TEXT) $e");
  db()->exec("CREATE TABLE IF NOT EXISTS psy_experts(
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(120) NOT NULL,
    pin VARCHAR(20) NOT NULL,
    link VARCHAR(300) DEFAULT '',
    direction VARCHAR(120) DEFAULT '',
    active TINYINT DEFAULT 1,
    tg_key VARCHAR(16) NOT NULL,
    tg_chat BIGINT NULL,
    created_at DATETIME NOT NULL) $e");
  db()->exec("CREATE TABLE IF NOT EXISTS psy_slots(
    id INT AUTO_INCREMENT PRIMARY KEY,
    expert_id INT NOT NULL,
    dt DATETIME NOT NULL,
    booking_id INT NULL,
    created_at DATETIME NOT NULL,
    UNIQUE KEY uniq_slot (expert_id, dt),
    KEY k_dt (dt)) $e");
  db()->exec("CREATE TABLE IF NOT EXISTS psy_bookings(
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
    created_at DATETIME NOT NULL,
    KEY k_dt (dt), KEY k_exp (expert_id)) $e");
  db()->exec("CREATE TABLE IF NOT EXISTS psy_tokens(
    token CHAR(32) PRIMARY KEY,
    role VARCHAR(10) NOT NULL,
    expert_id INT NULL,
    created_at DATETIME NOT NULL,
    last_used DATETIME NOT NULL) $e");
  db()->exec("CREATE TABLE IF NOT EXISTS psy_login(
    ip VARCHAR(45) PRIMARY KEY, fails INT DEFAULT 0, blocked_until DATETIME NULL) $e");
  db()->exec("CREATE TABLE IF NOT EXISTS psy_outbox(
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
  db()->exec("CREATE TABLE IF NOT EXISTS psy_sent(
    booking_id INT NOT NULL, kind VARCHAR(20) NOT NULL, at DATETIME NOT NULL,
    via VARCHAR(10) DEFAULT '',
    PRIMARY KEY (booking_id, kind)) $e");
  db()->exec("CREATE TABLE IF NOT EXISTS psy_tg(
    chat BIGINT PRIMARY KEY,
    role VARCHAR(10) NOT NULL,
    expert_id INT NULL,
    booking_id INT NULL,
    state VARCHAR(30) DEFAULT '',
    updated_at DATETIME NOT NULL) $e");
  db()->exec("CREATE TABLE IF NOT EXISTS psy_history(
    id INT AUTO_INCREMENT PRIMARY KEY,
    booking_id INT NOT NULL,
    at DATETIME NOT NULL,
    kind VARCHAR(20) NOT NULL,
    txt VARCHAR(400) NOT NULL,
    KEY k_b (booking_id), KEY k_at (at)) $e");
  /* Счётчик заходов: только числа по дням, никаких людей, адресов и устройств.
     Нужен, чтобы видеть конверсию «зашли → записались», а не следить за кем-то. */
  db()->exec("CREATE TABLE IF NOT EXISTS psy_visits(
    d DATE NOT NULL,
    source VARCHAR(40) NOT NULL DEFAULT '',
    step VARCHAR(10) NOT NULL DEFAULT 'open',
    n INT NOT NULL DEFAULT 0,
    PRIMARY KEY (d, source, step)) $e");
  db()->exec("CREATE TABLE IF NOT EXISTS psy_log(
    id INT AUTO_INCREMENT PRIMARY KEY, at DATETIME NOT NULL,
    kind VARCHAR(20), txt VARCHAR(500)) $e");
}

/* Доп. колонки появились позже — добавляем на лету, чтобы не ронять базу */
function migrate(){
  static $done; if($done) return; $done = 1;
  $have = [];
  foreach(all("SHOW COLUMNS FROM psy_experts") as $c) $have[$c['Field']] = 1;
  $add = [
    'slug'    => "VARCHAR(60) NULL",
    'tg_user' => "VARCHAR(60) DEFAULT ''",
    'email'   => "VARCHAR(120) DEFAULT ''",
    'phone'   => "VARCHAR(60) DEFAULT ''",
    'on_site' => "TINYINT DEFAULT 1",
  ];
  foreach($add as $col => $def){
    if(!isset($have[$col])) db()->exec("ALTER TABLE psy_experts ADD COLUMN `$col` $def");
  }
  if(!isset($have['slug'])) db()->exec("ALTER TABLE psy_experts ADD UNIQUE KEY uniq_slug (slug)");
  $haveB = [];
  foreach(all("SHOW COLUMNS FROM psy_bookings") as $c) $haveB[$c['Field']] = 1;
  if(!isset($haveB['direction'])) db()->exec("ALTER TABLE psy_bookings ADD COLUMN direction VARCHAR(60) DEFAULT ''");
  if(!isset($haveB['rep_upd']))   db()->exec("ALTER TABLE psy_bookings ADD COLUMN rep_upd DATETIME NULL");
  /* Связка с внешним видео-аккаунтом убрана целиком: ни колонки с номерами
     конференций, ни ключей в настройках не остаётся. */
  if(isset($haveB['zoom_id']))    db()->exec("ALTER TABLE psy_bookings DROP COLUMN zoom_id");
  if(one("SELECT k FROM psy_settings WHERE k LIKE 'zoom\\_%' OR k IN ('room_mode','shared_link','auto_link') LIMIT 1"))
    q("DELETE FROM psy_settings WHERE k LIKE 'zoom\\_%' OR k IN ('room_mode','shared_link','auto_link')");
  if(!isset($haveB['email']))     db()->exec("ALTER TABLE psy_bookings ADD COLUMN email VARCHAR(120) DEFAULT ''");
  foreach(['utm_source','utm_medium','utm_campaign','utm_term'] as $u)
    if(!isset($haveB[$u]))        db()->exec("ALTER TABLE psy_bookings ADD COLUMN `$u` VARCHAR(120) DEFAULT ''");
  if(!isset($haveB['hook_status']))db()->exec("ALTER TABLE psy_bookings ADD COLUMN hook_status VARCHAR(10) DEFAULT ''");
  if(!isset($haveB['hook_tries'])) db()->exec("ALTER TABLE psy_bookings ADD COLUMN hook_tries INT DEFAULT 0");
  if(!isset($haveB['pair']))       db()->exec("ALTER TABLE psy_bookings ADD COLUMN pair TINYINT DEFAULT 0");
  if(!isset($haveB['phone']))      db()->exec("ALTER TABLE psy_bookings ADD COLUMN phone VARCHAR(20) DEFAULT ''");
  if(!isset($haveB['tg_nick']))    db()->exec("ALTER TABLE psy_bookings ADD COLUMN tg_nick VARCHAR(64) DEFAULT ''");
  if(!isset($haveB['confirm']))    db()->exec("ALTER TABLE psy_bookings ADD COLUMN confirm VARCHAR(10) DEFAULT ''");
  if(!isset($haveB['confirm_at'])) db()->exec("ALTER TABLE psy_bookings ADD COLUMN confirm_at DATETIME NULL");
  if(!isset($haveB['gender']))     db()->exec("ALTER TABLE psy_bookings ADD COLUMN gender VARCHAR(5) DEFAULT ''");
  $haveS = [];
  foreach(all("SHOW COLUMNS FROM psy_sent") as $c) $haveS[$c['Field']] = 1;
  if(!isset($haveS['via'])) db()->exec("ALTER TABLE psy_sent ADD COLUMN via VARCHAR(10) DEFAULT ''");
  $haveT = [];
  foreach(all("SHOW COLUMNS FROM psy_tg") as $c) $haveT[$c['Field']] = 1;
  if(!isset($haveT['uname'])) db()->exec("ALTER TABLE psy_tg ADD COLUMN uname VARCHAR(64) DEFAULT ''");
  if(!isset($haveT['fname'])) db()->exec("ALTER TABLE psy_tg ADD COLUMN fname VARCHAR(120) DEFAULT ''");
  if(!isset($have['topics']))     db()->exec("ALTER TABLE psy_experts ADD COLUMN topics TEXT NULL");
  if(!isset($have['surname']))    db()->exec("ALTER TABLE psy_experts ADD COLUMN surname VARCHAR(80) DEFAULT ''");
  if(!isset($have['pairs']))      db()->exec("ALTER TABLE psy_experts ADD COLUMN pairs TINYINT DEFAULT 0");
  if(!isset($have['paused']))     db()->exec("ALTER TABLE psy_experts ADD COLUMN paused TINYINT DEFAULT 0");
  if(!isset($have['quota']))      db()->exec("ALTER TABLE psy_experts ADD COLUMN quota INT NULL");
  if(!isset($have['genders']))    db()->exec("ALTER TABLE psy_experts ADD COLUMN genders VARCHAR(5) DEFAULT 'any'");
  if(!isset($have['priority']))   db()->exec("ALTER TABLE psy_experts ADD COLUMN priority TINYINT DEFAULT 2");
  if(!isset($have['rules_at'])) db()->exec("ALTER TABLE psy_experts ADD COLUMN rules_at DATETIME NULL");
  if(!isset($have['name_fixed'])) db()->exec("ALTER TABLE psy_experts ADD COLUMN name_fixed TINYINT DEFAULT 0");
  if(!isset($have['is_test'])){
    db()->exec("ALTER TABLE psy_experts ADD COLUMN is_test TINYINT DEFAULT 0");
    /* карточка владельца — тестовая: на ней проверяют всё, и кураторов это дёргать не должно */
    q("UPDATE psy_experts SET is_test=1 WHERE name LIKE ? OR name LIKE ?", ['%Тестовый Специалист%', '%Тестовый Специалист%']);
  }
  if(!isset($have['first_slots_at'])){
    db()->exec("ALTER TABLE psy_experts ADD COLUMN first_slots_at DATETIME NULL");
    /* тем, кто уже открыл время до этой правки, ставим дату их первого слота —
       иначе кураторам прилетит пачка «новых» специалистов, которые давно работают */
    db()->exec("UPDATE psy_experts e SET first_slots_at =
                  (SELECT MIN(COALESCE(s.created_at, s.dt)) FROM psy_slots s WHERE s.expert_id = e.id)
                WHERE EXISTS (SELECT 1 FROM psy_slots s WHERE s.expert_id = e.id)");
  }
  /* Денег в системе больше нет — вычищаем и сами значения: суммы оплат, ставку школы
     и цены специалистов. Делается один раз, дальше эти колонки просто не существуют. */
  if(cfg('money_wiped') !== '1'){
    foreach(['psy_bookings' => ['paid', 'paid_sum'], 'psy_experts' => ['price']] as $table => $cols){
      $have = [];
      foreach(all("SHOW COLUMNS FROM `" . $table . "`") as $c) $have[$c['Field']] = 1;
      foreach($cols as $col)
        if(isset($have[$col])) db()->exec("ALTER TABLE `" . $table . "` DROP COLUMN `" . $col . "`");
    }
    q("DELETE FROM psy_settings WHERE k='rate'");
    setSetting('money_wiped', '1');
    logit('money', 'убраны суммы оплат, цены специалистов и ставка школы');
  }

  /* Автор изменения раньше был припиской «(клиент)» в конце текста — её не видно
     в списке и по ней не отфильтруешь. Теперь это отдельные поля. */
  $haveH = [];
  foreach(all("SHOW COLUMNS FROM psy_history") as $c) $haveH[$c['Field']] = 1;
  if(!isset($haveH['who'])){
    db()->exec("ALTER TABLE psy_history ADD COLUMN who VARCHAR(16) NOT NULL DEFAULT ''");
    db()->exec("ALTER TABLE psy_history ADD COLUMN who_name VARCHAR(80) NOT NULL DEFAULT ''");
    db()->exec("ALTER TABLE psy_history ADD COLUMN via VARCHAR(10) NOT NULL DEFAULT ''");
    /* старое разбираем по тексту, чтобы история до этой правки тоже отвечала «кто» */
    q("UPDATE psy_history SET who='client'  WHERE who='' AND txt LIKE '%(клиент)%'");
    q("UPDATE psy_history SET who='curator' WHERE who='' AND txt LIKE '%(координатор)%'");
    q("UPDATE psy_history SET who='expert'  WHERE who='' AND kind='confirm'");
    q("UPDATE psy_history SET who='system'  WHERE who=''");
  }
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
/* Показываем всех одинаково: сперва фамилия, потом имя.
   Порядок слов в таблице школы разный («Елена Соколова» и «Козлова Ирина»),
   а список должен читаться как список — по фамилиям. */
function orderName($name, $surname = ''){
  $words = preg_split('/\s+/u', trim((string)$name), -1, PREG_SPLIT_NO_EMPTY);
  if(count($words) < 2) return trim((string)$name);
  if(trim((string)$surname) === '') $surname = surnameOf($name);
  $i = array_search($surname, $words, true);
  if($i === false || $i === 0) return implode(' ', $words);
  array_splice($words, $i, 1);
  return $surname . ' ' . implode(' ', $words);
}
/* Версия выложенного интерфейса: по ней страница понимает, что устарела */
function appVer(){
  $f = __DIR__ . '/app.js';
  return (string)(@filemtime($f) ?: 0);
}
function sortKey($s){
  return str_replace('ё', 'е', mb_strtolower(trim((string)$s)));
}
function backfillSurnames(){
  foreach(all("SELECT id, name, slug, surname, name_fixed FROM psy_experts") as $e){
    $sn = surnameOf($e['name'], $e['slug'] ?? '');
    $nm = empty($e['name_fixed']) ? orderName($e['name'], $sn) : $e['name'];
    if($sn !== ($e['surname'] ?? '') || $nm !== $e['name'])
      q("UPDATE psy_experts SET surname=?, name=? WHERE id=?", [$sn, $nm, $e['id']]);
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
/* ── Темы запросов ───────────────────────────────────────────
   Список сгруппирован: клиентка выбирает конкретный запрос внутри раздела,
   специалист отмечает у себя то же самое. Старые восемь тем никуда не делись —
   они разложены по новым через LEGACY_TOPICS() ниже, поэтому и прежние отметки
   специалистов, и записи с прежними формулировками продолжают работать. */
function TOPIC_GROUPS(){
  return [
    ['title' => '🔥 Сексуальность и интимность', 'items' => [
      'Пропало желание / низкое либидо',
      'Сложности с оргазмом или возбуждением',
      'Боль и дискомфорт во время близости',
      'Разный темперамент с партнёром',
      'Стыд, страхи и зажимы в сексе',
      'Возвращение сексуальности после родов или стресса',
      'Сложности в сексуальной сфере',
    ]],
    ['title' => '👫 Отношения и семья', 'items' => [
      'Кризис в паре, ссоры, отчуждение',
      'Измены, ревность, нарушение доверия',
      'Неумение договариваться и слышать друг друга',
      'Сложности с личными границами',
      'Проблемы в отношениях с родителями или детьми',
      'Переживание развода или расставания',
    ]],
    ['title' => '🧠 Состояние и эмоции', 'items' => [
      'Тревога, панические атаки, страхи',
      'Апатия, выгорание, хроническая усталость',
      'Низкая самооценка, неуверенность в себе',
      'Потеря смыслов, поиск себя',
      'Обиды, чувство вины, внутренний конфликт',
      'Стресс и эмоциональный перегруз',
    ]],
    ['title' => '🧘‍♀️ Тело и женское здоровье', 'items' => [
      'Восстановление после родов (диастаз, эстетика)',
      'Проблемы тазового дна (недержание, опущение)',
      'Психосоматика и хронические зажимы в теле',
      'Потеря чувствительности, разрыв связи с телом',
      'Интимная гимнастика и профилактика',
    ]],
    ['title' => '🚀 Личностный рост и коучинг', 'items' => [
      'Выход из жизненного тупика',
      'Целеполагание и поиск мотивации',
      'Баланс между карьерой, семьёй и собой',
      'Раскрытие потенциала и уверенности',
    ]],
    ['title' => '💬 Пока не сформулировала', 'items' => [
      'Другое / пока не сформулировала',
    ]],
  ];
}
function TOPICS(){
  static $flat = null;
  if($flat !== null) return $flat;
  $flat = [];
  foreach(TOPIC_GROUPS() as $g) foreach($g['items'] as $t) $flat[] = $t;
  return $flat;
}
/* Прежние восемь тем → во что они разворачиваются в новом списке.
   Нужны в двух местах: разово переносим отметки специалистов и понимаем
   старые записи, у которых тема записана прежней формулировкой. */
function LEGACY_TOPICS(){
  return [
    'Отношения с партнёром' => [
      'Кризис в паре, ссоры, отчуждение',
      'Измены, ревность, нарушение доверия',
      'Неумение договариваться и слышать друг друга',
      'Сложности с личными границами',
      'Разный темперамент с партнёром',
    ],
    'Близость и сексуальность' => [
      'Пропало желание / низкое либидо',
      'Сложности с оргазмом или возбуждением',
      'Боль и дискомфорт во время близости',
      'Разный темперамент с партнёром',
      'Стыд, страхи и зажимы в сексе',
      'Возвращение сексуальности после родов или стресса',
      'Сложности в сексуальной сфере',
    ],
    'После родов, тело и восстановление' => [
      'Восстановление после родов (диастаз, эстетика)',
      'Проблемы тазового дна (недержание, опущение)',
      'Психосоматика и хронические зажимы в теле',
      'Потеря чувствительности, разрыв связи с телом',
      'Возвращение сексуальности после родов или стресса',
    ],
    'Уверенность в себе' => [
      'Низкая самооценка, неуверенность в себе',
      'Потеря смыслов, поиск себя',
      'Раскрытие потенциала и уверенности',
    ],
    'Кризис, расставание, развод' => [
      'Переживание развода или расставания',
      'Кризис в паре, ссоры, отчуждение',
      'Обиды, чувство вины, внутренний конфликт',
    ],
    'Семья и дети' => [
      'Проблемы в отношениях с родителями или детьми',
      'Баланс между карьерой, семьёй и собой',
    ],
    'Интимная гимнастика' => [
      'Интимная гимнастика и профилактика',
      'Проблемы тазового дна (недержание, опущение)',
    ],
    'Другое / пока не сформулировала' => ['Другое / пока не сформулировала'],
    /* тема была переименована — прежнее название продолжаем понимать */
    'Сексуальное расстройство' => ['Сложности в сексуальной сфере'],
  ];
}
/* Разворачивает старые формулировки в новые; новые оставляет как есть */
function expandTopics($list){
  $map = LEGACY_TOPICS();
  $out = [];
  foreach((array)$list as $t){
    $t = trim((string)$t);
    if($t === '') continue;
    if(isset($map[$t])){ foreach($map[$t] as $n) $out[] = $n; continue; }
    $out[] = $t;
  }
  return array_values(array_unique($out));
}
function topicsOf($expertRow){
  $t = trim((string)($expertRow['topics'] ?? ''));
  if($t === '') return TOPICS();          /* не отметила ничего — значит, берётся за любую тему */
  $a = json_decode($t, true);
  if(!is_array($a) || !$a) return TOPICS();
  /* в отметках может лежать прежняя формулировка — разворачиваем её в новые темы */
  $a = expandTopics($a);
  return array_values(array_intersect(TOPICS(), $a));
}
/* С кем работает специалист: any — со всеми, f — только женщины, m — только мужчины */
function gendersOf($e){
  $g = trim((string)($e['genders'] ?? ''));
  return in_array($g, ['any', 'f', 'm'], true) ? $g : 'any';
}
function worksGender($e, $gender){
  if($gender === '' || $gender === 'any') return true;
  $g = gendersOf($e);
  return $g === 'any' || $g === $gender;
}
function takesPairs($expertRow){ return (int)($expertRow['pairs'] ?? 0) === 1; }
function worksWith($expertRow, $topic){
  $topic = trim((string)$topic);
  if($topic === '') return true;
  $mine = topicsOf($expertRow);
  if(in_array($topic, $mine, true)) return true;
  /* запись могла быть создана со старой формулировкой — сверяем по её новым темам */
  $map = LEGACY_TOPICS();
  if(isset($map[$topic])) foreach($map[$topic] as $n) if(in_array($n, $mine, true)) return true;
  return false;
}

/* ── Направления ────────────────────────────────────────────── */
function DIRECTIONS(){
  return ['Секс-коуч', 'Семейный психолог', 'Тренер интимной гимнастики'];
}
function normDirection($s){
  $s = trim((string)$s);
  if($s === '') return '';
  $s = preg_replace('/\s+/u', ' ', $s);
  $first = trim(explode(',', $s)[0]);
  $low = mb_strtolower($first);
  if(mb_strpos($low, 'секс') !== false)   return 'Секс-коуч';
  if(mb_strpos($low, 'семей') !== false)  return 'Семейный психолог';
  if(mb_strpos($low, 'гимнаст') !== false) return 'Тренер интимной гимнастики';
  return $first;
}

/* Загрузка состава из Google-таблицы убрана 30.08.2026 по решению владельца:
   состав ведётся только вручную в кабинете. Вместе с ней убраны чтение CSV
   и подбор ключа по строке таблицы — они больше нигде не нужны. */

function defaults(){
  return [
    /* Коды первого запуска. Живые значения лежат только в базе (psy_settings)
       и меняются в Настройках. В репозитории паролей нет. */
    'admin_pin'   => '0000',   /* координаторы: всё, кроме настроек */
    'owner_pin'   => '0000',   /* владелец: плюс настройки и интеграции */
    'quota'       => '5',
    'duration'    => '50',
    'tz'          => 'Europe/Moscow',
    'tg_token'    => '',
    'bot_user'    => '',
    'relay_key'   => '',
    'admin_key'   => '',
    'remind24'    => '1',
    'remind2'     => '1',
    'remind15'    => '1',
    'ask_csat'    => '1',
    /* показывать время только тех, кто подтвердил договорённости */
    'rules_gate'  => '0',
    'digest_on'   => '1',
    'digest_at'   => '21:00',
    'org'         => 'Психология и коучинг',
    'alert_email' => '',
    'sms_on'      => '0',
    'sms_email'   => '',
    'sms_key'     => '',
    'sms_sign'    => 'SMS Aero',
    /* денег в системе больше нет: ставки, оплаты и выплаты убраны из всех кабинетов */
    'hook_on'     => '0',
    'hook_url'    => '',
    'site_url'    => '',
    /* Внешних видео-аккаунтов у системы нет: встречу ведёт специалист
       в своей комнате, и ссылка на неё — обязательное поле кабинета. */
  ];
}
$PSY_S = null;
function settings(){
  global $PSY_S;
  if($PSY_S) return $PSY_S;
  initTables();
  migrate();
  $PSY_S = defaults();
  foreach(all("SELECT k,v FROM psy_settings") as $r) $PSY_S[$r['k']] = $r['v'];
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
  q("INSERT INTO psy_settings(k,v) VALUES(?,?) ON DUPLICATE KEY UPDATE v=VALUES(v)", [$k, (string)$v]);
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
    if(!one("SELECT code FROM psy_bookings WHERE code=?", [$c])) return $c;
  }
  return strtoupper(substr(bin2hex(random_bytes(4)), 0, 6));
}
function logit($kind, $txt){
  try{ q("INSERT INTO psy_log(at,kind,txt) VALUES(?,?,?)", [now(), $kind, mb_substr($txt, 0, 480)]); }catch(Throwable $e){}
}
/* Короткая подпись часового пояса — чтобы клиентка из другого города
   не считала время по-своему. Пустая, если школа живёт не по Москве. */
function tzShort(){
  return (cfg('tz') ?: 'Europe/Moscow') === 'Europe/Moscow' ? ' мск' : '';
}
function fmtRu($dt){
  $days  = ['Sunday'=>'воскресенье','Monday'=>'понедельник','Tuesday'=>'вторник','Wednesday'=>'среда',
            'Thursday'=>'четверг','Friday'=>'пятница','Saturday'=>'суббота'];
  $mon   = [1=>'января',2=>'февраля',3=>'марта',4=>'апреля',5=>'мая',6=>'июня',7=>'июля',
            8=>'августа',9=>'сентября',10=>'октября',11=>'ноября',12=>'декабря'];
  $t = ts($dt);
  return $days[date('l', $t)] . ', ' . (int)date('j', $t) . ' ' . $mon[(int)date('n', $t)] . ', ' . date('H:i', $t) . tzShort();
}

/* ── Авторизация ────────────────────────────────────────────── */
function clientIp(){ return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0'; }
function loginBlocked(){
  $r = one("SELECT * FROM psy_login WHERE ip=?", [clientIp()]);
  return $r && $r['blocked_until'] && ts($r['blocked_until']) > time();
}
function loginFail(){
  $ip = clientIp();
  $r  = one("SELECT * FROM psy_login WHERE ip=?", [$ip]);
  $f  = ($r ? (int)$r['fails'] : 0) + 1;
  $bu = $f >= 7 ? date('Y-m-d H:i:s', time() + 900) : null;
  q("INSERT INTO psy_login(ip,fails,blocked_until) VALUES(?,?,?)
     ON DUPLICATE KEY UPDATE fails=VALUES(fails), blocked_until=VALUES(blocked_until)", [$ip, $f, $bu]);
}
function loginOk(){ q("DELETE FROM psy_login WHERE ip=?", [clientIp()]); }

function issueToken($role, $expertId = null){
  $t = rnd(32);
  q("INSERT INTO psy_tokens(token,role,expert_id,created_at,last_used) VALUES(?,?,?,?,?)",
    [$t, $role, $expertId, now(), now()]);
  q("DELETE FROM psy_tokens WHERE last_used < ?", [date('Y-m-d H:i:s', time() - 45*86400)]);
  return $t;
}
function bearer(){
  $h = $_SERVER['HTTP_AUTHORIZATION'] ?? ($_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '');
  if($h === '' && function_exists('getallheaders')){
    foreach((array)getallheaders() as $k => $v){ if(strcasecmp($k, 'Authorization') === 0){ $h = $v; break; } }
  }
  return (stripos($h, 'Bearer ') === 0) ? trim(substr($h, 7)) : '';
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
    $r = one("SELECT * FROM psy_tokens WHERE token=?", [$t]);
    if($r){
      q("UPDATE psy_tokens SET last_used=? WHERE token=?", [now(), $t]);
      $a = $r;
    }
  }
  return $a;
}
function requireRole($role){
  $a = auth();
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

function expert($id){ return one("SELECT * FROM psy_experts WHERE id=?", [(int)$id]); }
/* Личная квота специалиста; не задана — берём общую из настроек */
/* Приоритет: 1 — зелёный, отдаём клиентов первым; 2 — обычный; 3 — красный, в последнюю очередь */
function priorityOf($e){
  if(is_numeric($e)) $e = expert($e);
  $p = (int)($e['priority'] ?? 2);
  return ($p >= 1 && $p <= 3) ? $p : 2;
}
function priorityName($p){
  return [1 => 'в первую очередь', 2 => 'обычный', 3 => 'в последнюю очередь'][(int)$p] ?? 'обычный';
}
function quotaOf($e){
  if(is_numeric($e)) $e = expert($e);
  $q = (int)($e['quota'] ?? 0);
  return $q > 0 ? $q : (int)cfg('quota');
}
/* Сколько мест квоты реально занято. Отменённые встречи и неявки не считаем:
   специалист не виноват, что клиент не дошёл, — место должно вернуться в оборот,
   и её время снова показывается, пока не наберётся полная квота живых записей.
   Специалисту про закрытие показа не сообщаем: это внутренняя механика распределения. */
function loadOf($id){
  $r = one("SELECT COUNT(*) c FROM psy_bookings
             WHERE expert_id=? AND status IN ('booked','held')", [(int)$id]);
  return (int)$r['c'];
}

function reportDebt($id){
  $cut = date('Y-m-d H:i:s', time() - 24*3600 - ((int)cfg('duration')) * 60);
  $r = one("SELECT COUNT(*) c FROM psy_bookings
             WHERE expert_id=? AND rep_at IS NULL AND status IN ('booked','held') AND dt < ?", [(int)$id, $cut]);
  return (int)$r['c'];
}
/* Свободные времена: клиент видит только время, без имён.
   Возвращаем ещё и направления, свободные на этот час. */
function openTimes(){
  $rows  = all(
    "SELECT s.dt, s.expert_id, e.direction, e.topics, e.pairs, e.genders, e.link FROM psy_slots s
       JOIN psy_experts e ON e.id = s.expert_id
      WHERE s.booking_id IS NULL AND e.active = 1 AND COALESCE(e.paused,0) = 0 AND s.dt > ?
      ORDER BY s.dt", [now()]);
  $out = [];
  foreach($rows as $r){
    if(!ownRoom($r)) continue;                       /* нет своей ссылки — клиентке некуда прийти */
    if(!rulesOk($r['expert_id'])) continue;          /* не подтвердил договорённости — время скрыто */
    if(loadOf($r['expert_id']) >= quotaOf($r['expert_id'])) continue;
    if(!isset($out[$r['dt']])) $out[$r['dt']] = ['dirs' => [], 'topics' => [], 'pairTopics' => [], 'ex' => []];
    $d = $r['direction'] !== '' ? $r['direction'] : 'Без направления';
    $out[$r['dt']]['dirs'][$d] = true;
    foreach(topicsOf($r) as $t){
      $out[$r['dt']]['topics'][$t] = true;
      if(takesPairs($r)) $out[$r['dt']]['pairTopics'][$t] = true;
    }
    $out[$r['dt']]['ex'][] = ['t' => topicsOf($r), 'p' => takesPairs($r) ? 1 : 0, 'g' => gendersOf($r)];
  }
  $res = [];
  foreach($out as $dt => $sets){
    $res[] = ['dt' => $dt, 'dirs' => array_keys($sets['dirs']),
              'topics' => array_keys($sets['topics']),
              'pairTopics' => array_keys($sets['pairTopics']),
              'ex' => $sets['ex']];
  }
  return $res;
}
function openTimeList(){
  $r = [];
  foreach(openTimes() as $t) $r[] = $t['dt'];
  return $r;
}
/* Встречу специалист ведёт у себя: ссылка на его комнату — обязательное
   поле кабинета, и пока её нет, время клиенткам не показывается. */
function ownRoom($expertRow){ return trim((string)($expertRow['link'] ?? '')) !== ''; }

function meetingLink($expertRow, $code){
  return trim((string)($expertRow['link'] ?? ''));
}

/* Записи всегда смотрят на ту комнату, которая сейчас указана у специалиста:
   поменял ссылку — будущие встречи переезжают за ней. */
function syncMeetingLink($b){
  $e = expert($b['expert_id']);
  if(!$e) return $b;
  if(trim((string)$b['link']) !== trim((string)$e['link']))
    q("UPDATE psy_bookings SET link=? WHERE id=?", [trim((string)$e['link']), $b['id']]);
  return one("SELECT * FROM psy_bookings WHERE id=?", [$b['id']]);
}

/* ── Передача лида во внешний агрегатор ──────────────────────
   POST с полями name / phone / utm_*. Уходит после создания записи;
   если не дошло — повторяем в планировщике, до пяти раз. */
function hookOn(){ return cfg('hook_on') === '1' && trim((string)cfg('hook_url')) !== ''; }
function hookPayload($b){
  /* Телефон берём из своего поля через общий разбор: в поле «связь» у новых
     записей его нет вовсе, и партнёру уходила бы заявка без номера. */
  $phone = bookingPhone($b);
  if($phone === '') $phone = trim((string)($b['contact'] ?? ''));
  return [
    'name'         => (string)$b['client_name'],
    'phone'        => $phone,
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
  q("UPDATE psy_bookings SET hook_status=?, hook_tries=hook_tries+1 WHERE id=?",
    [$ok ? 'sent' : 'pending', $b['id']]);
  if(!$ok) logit('hook', 'запись ' . $b['code'] . ': ' . $msg);
}
function hookRetry(){
  if(!hookOn()) return;
  $rows = all("SELECT * FROM psy_bookings
                WHERE hook_status='pending' AND hook_tries < 5 AND created_at > ?",
              [date('Y-m-d H:i:s', time() - 3*86400)]);
  foreach($rows as $b) hookSend($b);
}

function makeBooking($when, $name, $contact, $cat, $note, $forceExpert = null, $ignoreQuota = false, $direction = '', $email = '', $utm = [], $pair = false, $phone = '', $tgNick = '', $gender = ''){
  initTables();
  $pdo   = db();
  $pdo->beginTransaction();
  try{
    $cands = all(
      "SELECT s.id, s.expert_id FROM psy_slots s
         JOIN psy_experts e ON e.id = s.expert_id
        WHERE s.dt = ? AND s.booking_id IS NULL AND e.active = 1
          AND (? = 1 OR COALESCE(e.paused,0) = 0)
          AND (? = '' OR e.direction = ?)
        ORDER BY s.id FOR UPDATE", [$when, $forceExpert ? 1 : 0, $direction, $direction]);
    $best = null; $bestLoad = PHP_INT_MAX;
    if($forceExpert){
      /* координатор назначает конкретного человека — при необходимости открываем слот за него */
      foreach($cands as $c) if((int)$c['expert_id'] === (int)$forceExpert){ $best = $c; break; }
      if(!$best){
        $fe = expert($forceExpert);
        if(!$fe){ $pdo->rollBack(); return null; }
        $busy = one("SELECT id FROM psy_slots WHERE expert_id=? AND dt=?", [$forceExpert, $when]);
        if($busy){ $pdo->rollBack(); return null; }   /* это время у него уже занято */
        q("INSERT INTO psy_slots(expert_id, dt, created_at) VALUES(?,?,?)", [$forceExpert, $when, now()]);
        $best = ['id' => (int)$pdo->lastInsertId(), 'expert_id' => (int)$forceExpert];
      }
    }else{
      /* Два прохода: сначала те, кто сдал отчёты, и только если таких нет —
         остальные. Запись не должна срываться из-за чужой дисциплины. */
      foreach([false, true] as $withDebtors){
        $bestLoad = PHP_INT_MAX; $best = null;
        foreach($cands as $c){
          $ex = expert($c['expert_id']);
          if(!$ex || !rulesOk($ex)) continue;              /* без подтверждённых договорённостей клиентов не даём */
          if(!ownRoom($ex)) continue;                      /* нет своей комнаты — вести встречу негде */
          if(!worksWith($ex, $cat)) continue;              /* тема, с которой она работает */
          if($pair && !takesPairs($ex)) continue;          /* парные берёт не каждый */
          if($pair){ if(gendersOf($ex) !== 'any') continue; }  /* в паре обычно и мужчина, и женщина */
          elseif(!worksGender($ex, $gender)) continue;      /* с кем работает специалист */
          $l = loadOf($c['expert_id']);
          if(!$ignoreQuota && $l >= quotaOf($ex)) continue;
          if(!$withDebtors && reportDebt($c['expert_id']) > 0) continue;
          /* ключ: приоритет важнее загрузки — зелёные набирают первыми */
          $key = priorityOf($ex) * 10000 + $l;
          if($key < $bestLoad){ $bestLoad = $key; $best = $c; }
        }
        if($best) break;
      }
    }
    if(!$best){ $pdo->rollBack(); return null; }

    $e    = expert($best['expert_id']);
    $code = newCode();
    $link = meetingLink($e, $code);
    q("INSERT INTO psy_bookings(code,expert_id,slot_id,dt,client_name,contact,email,category,note,link,direction,
         utm_source,utm_medium,utm_campaign,utm_term,pair,phone,tg_nick,gender,status,created_at)
       VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?, 'booked', ?)",
       [$code, $e['id'], $best['id'], $when, $name, $contact, $email, $cat, $note, $link,
        ($direction !== '' ? $direction : (string)$e['direction']),
        mb_substr((string)($utm['utm_source'] ?? ''), 0, 110),
        mb_substr((string)($utm['utm_medium'] ?? ''), 0, 110),
        mb_substr((string)($utm['utm_campaign'] ?? ''), 0, 110),
        mb_substr((string)($utm['utm_term'] ?? ''), 0, 110), $pair ? 1 : 0,
        mb_substr($phone, 0, 20), ltrim(mb_substr($tgNick, 0, 60), '@'),
        in_array($gender, ['f','m'], true) ? $gender : '', now()]);
    $bid = (int)$pdo->lastInsertId();
    q("UPDATE psy_slots SET booking_id=? WHERE id=?", [$bid, $best['id']]);
    $pdo->commit();

    /* Напоминания, срок которых уже прошёл, не шлём */
    $t   = ts($when);
    $dur = (int)cfg('duration');
    /* 'skip' — напоминание не отправляли: запись появилась, когда срок уже прошёл */
    if(time() > $t - 24*3600) markSent($bid, 'r24', 'skip');
    if(time() > $t - 2*3600){ markSent($bid, 'r2', 'skip'); markSent($bid, 'e2', 'skip'); }
    if(time() > $t - 15*60) markSent($bid, 'r15', 'skip');

    $b = one("SELECT * FROM psy_bookings WHERE id=?", [$bid]);
    hookSend($b);
    /* Подтверждение сразу после записи. Клиенту с подключённым ботом оно уходит
       в Telegram и бесплатно, всем остальным — SMS. Результат виден в истории записи:
       раньше эта отправка была молчаливой, и понять, ушла она или нет, было нельзя. */
    if(empty($b['tg_chat'])){
      if(!smsOn()){
        histAdd($bid, 'sms', 'Подтверждение по SMS не отправляли: SMS выключены в настройках', actor('system'));
      }elseif(bookingPhone($b) === ''){
        histAdd($bid, 'sms', 'Подтверждение по SMS не отправляли: телефона нет, в контакте «' . $b['contact'] . '»', actor('system'));
      }else{
        $sent = smsSend(bookingPhone($b), $b['client_name'] . ', вы записаны ' . date('d.m', ts($b['dt'])) . ' в ' .
          date('H:i', ts($b['dt'])) . tzShort() . ', ' . $e['name'] . '. Ссылка: ' . smsUrl($b));
        markSent($bid, 'new', $sent ? 'sms' : 'none');
        if(!$sent){
          $why = trim((string)($GLOBALS['PSY_SMS_ERR'] ?? ''));
          histAdd($bid, 'sms', 'Подтверждение по SMS не ушло' . ($why !== '' ? ': ' . $why : ''), actor('system'));
        }
      }
    }else{
      markSent($bid, 'new', 'tg');
    }
    if(!empty($b['email'])){
      sendMail($b['email'], 'Вы записаны — ' . fmtRu($b['dt']),
        "Здравствуйте, " . $b['client_name'] . "!\n\n" . meetingCard($b, $e) . "\n\n" .
        "Напомним за сутки и за два часа.\n" .
        "Перенести или отменить: " . siteUrl() . "?code=" . $b['code'] .
        ($e['tg_chat'] || cfg('bot_user') ? "\nНапоминания в Telegram: " . botLink('b' . $b['code']) : ''));
    }
    notifyNewBooking($b, $e);
    return $b;
  }catch(Throwable $ex){
    if($pdo->inTransaction()) $pdo->rollBack();
    logit('book', $ex->getMessage());
    return null;
  }
}

/* Кто и что менял в профиле специалиста — чтобы любую правку можно было откатить руками */
function auditExpert($expertId, $what, $before, $after, $who = ''){
  db()->exec("CREATE TABLE IF NOT EXISTS psy_audit(
    id INT AUTO_INCREMENT PRIMARY KEY, at DATETIME NOT NULL, expert_id INT NOT NULL,
    who VARCHAR(20) DEFAULT '', what VARCHAR(30) NOT NULL,
    before_val TEXT, after_val TEXT, KEY k_e (expert_id, id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
  if((string)$before === (string)$after) return;
  q("INSERT INTO psy_audit(at, expert_id, who, what, before_val, after_val) VALUES(?,?,?,?,?,?)",
    [now(), (int)$expertId, $who, $what, mb_substr((string)$before, 0, 2000), mb_substr((string)$after, 0, 2000)]);
}
/* ── Кто сделал изменение ────────────────────────────────────
   На вопрос «кто перенёс встречу» нужен точный ответ, поэтому автор
   события пишется отдельными полями: роль, имя (если это специалист)
   и канал — сайт, бот или кабинет. */
function actor($who, $name = '', $via = ''){
  return ['who' => $who, 'name' => (string)$name, 'via' => $via];
}
function actorLabel($a){
  $map = ['client' => 'клиент', 'expert' => 'специалист', 'curator' => 'куратор',
          'owner' => 'владелец', 'system' => 'система'];
  $s = $map[$a['who'] ?? ''] ?? 'система';
  if(!empty($a['name'])) $s .= ' ' . $a['name'];
  return $s;
}
/* Автор — тот, кто сейчас вошёл: куратор, владелец или сам специалист */
function actorAuth($via = 'panel'){
  $a = auth();
  if(!$a) return actor('system', '', $via);
  if($a['role'] === 'expert'){ $e = expert($a['expert_id']); return actor('expert', $e['name'] ?? '', $via); }
  if($a['role'] === 'owner')  return actor('owner', '', $via);
  return actor('curator', '', $via);
}
/* Автор события в том виде, в каком его показывает кабинет */
function who($a){
  $viaMap = ['site' => 'на сайте', 'bot' => 'через бота', 'panel' => 'в кабинете'];
  $w = (string)($a['who'] ?? '');
  if($w === '') $w = 'system';
  return ['who' => $w, 'whoLabel' => actorLabel($a), 'via' => $viaMap[$a['via'] ?? ''] ?? ''];
}
function histAdd($bookingId, $kind, $txt, $a = null){
  settings();                       /* заодно доводит таблицы до нужного вида */
  if(!is_array($a)) $a = actor('system');
  q("INSERT INTO psy_history(booking_id, at, kind, txt, who, who_name, via) VALUES(?,?,?,?,?,?,?)",
    [(int)$bookingId, now(), $kind, mb_substr($txt, 0, 380),
     (string)($a['who'] ?? 'system'), mb_substr((string)($a['name'] ?? ''), 0, 80), (string)($a['via'] ?? '')]);
}

/* ── Снять специалиста и искать нового ───────────────────────
   Тихая внутренняя операция: клиентке не пишем, время за ней остаётся,
   прежний специалист перестаёт видеть встречу у себя. */
function unassignBooking($b, $reason, $by = null){
  if(!is_array($by)) $by = actor('curator');
  if(!$b || $b['status'] === 'cancelled') return [false, 'Запись отменена.'];
  $old = expert($b['expert_id']);
  q("UPDATE psy_slots SET booking_id=NULL WHERE id=? AND booking_id=?", [$b['slot_id'], $b['id']]);
  q("UPDATE psy_bookings SET status='searching' WHERE id=?", [$b['id']]);
  /* напоминания не шлём, пока нет специалиста */
  q("DELETE FROM psy_sent WHERE booking_id=? AND kind IN ('r24','r2','r15','e2','eask','rep')", [$b['id']]);
  histAdd($b['id'], 'search', 'Снят специалист ' . ($old['name'] ?? '—') . ', ищем нового' .
          ($reason !== '' ? '. Причина: ' . $reason : ''), $by);
  return [true, one("SELECT * FROM psy_bookings WHERE id=?", [$b['id']])];
}

/* Назначить специалиста на «висящую» запись — вот теперь клиентке пишем. */
function assignBooking($b, $newExpertId, $by = null){
  if(!is_array($by)) $by = actor('curator');
  $new = expert($newExpertId);
  if(!$new) return [false, 'Такого специалиста нет.'];
  if(!empty($b['pair']) && !takesPairs($new))
    return [false, $new['name'] . ' не берёт парные консультации, а эта встреча парная.'];
  if(!empty($b['gender']) && empty($b['pair']) && !worksGender($new, $b['gender']))
    return [false, $new['name'] . ' не работает с ' . ($b['gender'] === 'm' ? 'мужчинами' : 'женщинами') . '.'];
  if(!worksWith($new, $b['category']))
    return [false, $new['name'] . ' не работает с темой «' . $b['category'] . '». Отметьте тему у неё в кабинете или выберите другого.'];

  $pdo = db();
  $pdo->beginTransaction();
  try{
    $slot = one("SELECT * FROM psy_slots WHERE expert_id=? AND dt=? FOR UPDATE", [$new['id'], $b['dt']]);
    if($slot && $slot['booking_id'] && (int)$slot['booking_id'] !== (int)$b['id']){
      $pdo->rollBack();
      return [false, 'У ' . $new['name'] . ' это время уже занято.'];
    }
    if(!$slot){
      q("INSERT INTO psy_slots(expert_id, dt, created_at) VALUES(?,?,?)", [$new['id'], $b['dt'], now()]);
      $slotId = (int)$pdo->lastInsertId();
    }else $slotId = (int)$slot['id'];
    q("UPDATE psy_slots SET booking_id=? WHERE id=?", [$b['id'], $slotId]);
    q("UPDATE psy_bookings SET expert_id=?, slot_id=?, direction=?, status='booked' WHERE id=?",
      [$new['id'], $slotId, (string)$new['direction'], $b['id']]);
    $pdo->commit();
  }catch(Throwable $ex){
    if($pdo->inTransaction()) $pdo->rollBack();
    return [false, 'Не получилось: ' . $ex->getMessage()];
  }

  $nb = syncMeetingLink(one("SELECT * FROM psy_bookings WHERE id=?", [$b['id']]));
  histAdd($nb['id'], 'assign', 'Назначен специалист ' . $new['name'], $by);

  tell($new['tg_chat'], $new['email'] ?? '', 'Вам передали встречу — ' . fmtRu($nb['dt']),
    "🔔 Вам передали встречу\n" . fmtRu($nb['dt']) . (!empty($nb['pair']) ? " · ПАРНАЯ" : "") .
    "\n\nКлиент: " . $nb['client_name'] . "\nТема: " . $nb['category'] .
    ($nb['note'] ? "\n«" . $nb['note'] . "»" : '') . "\nСвязь: " . $nb['contact'] .
    "\nСсылка: " . ($nb['link'] ?: '—'), true);

  $ctxt = "Ваша встреча " . fmtRu($nb['dt']) . " подтверждена.\nСпециалист: " . $new['name'] .
          "\nСсылка: " . ($nb['link'] ?: 'пришлём отдельно');
  $sms  = 'Встреча ' . date('d.m', ts($nb['dt'])) . ' в ' . date('H:i', ts($nb['dt'])) . ', ' .
          $new['name'] . '. Ссылка: ' . smsUrl($nb);
  $via = tell($nb['tg_chat'], $nb['email'], 'Ваша встреча подтверждена', $ctxt, true, bookingPhone($nb), $sms);
  histAdd($nb['id'], 'notify', 'Клиентке отправлено подтверждение с новым специалистом' .
          ($via ? ' (' . ['tg'=>'Telegram','sms'=>'SMS','mail'=>'почта'][$via] . ')' : ' — канала нет, сообщите вручную'),
          actor('system'));
  adminNotify("✅ Назначен специалист: " . $new['name'] . "\n" . $nb['client_name'] . ", " . fmtRu($nb['dt']));
  return [true, $nb, $via];
}

/* ── Замена специалиста ──────────────────────────────────────
   Встреча остаётся: то же время, тот же клиент, та же ссылка.
   Меняется только тот, кто её проводит — и это попадает в историю. */
function changeExpert($b, $newExpertId, $reason, $by = null){
  if(!is_array($by)) $by = actor('curator');
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
    $slot = one("SELECT * FROM psy_slots WHERE expert_id=? AND dt=? FOR UPDATE", [$new['id'], $b['dt']]);
    if($slot && $slot['booking_id'] && (int)$slot['booking_id'] !== (int)$b['id']){
      $pdo->rollBack();
      return [false, 'У ' . $new['name'] . ' это время уже занято другой встречей.'];
    }
    if(!$slot){
      q("INSERT INTO psy_slots(expert_id, dt, created_at) VALUES(?,?,?)", [$new['id'], $b['dt'], now()]);
      $slotId = (int)$pdo->lastInsertId();
    }else{
      $slotId = (int)$slot['id'];
    }
    q("UPDATE psy_slots SET booking_id=NULL WHERE id=? AND booking_id=?", [$b['slot_id'], $b['id']]);
    q("UPDATE psy_slots SET booking_id=? WHERE id=?", [$b['id'], $slotId]);
    q("UPDATE psy_bookings SET expert_id=?, slot_id=?, direction=? WHERE id=?",
      [$new['id'], $slotId, (string)$new['direction'], $b['id']]);
    $pdo->commit();
  }catch(Throwable $ex){
    if($pdo->inTransaction()) $pdo->rollBack();
    logit('change', $ex->getMessage());
    return [false, 'Не получилось: ' . $ex->getMessage()];
  }

  histAdd($b['id'], 'expert', 'Специалист заменён: ' . ($old['name'] ?? '—') . ' → ' . $new['name'] .
          ($reason !== '' ? '. Причина: ' . $reason : ''), $by);

  $nb = syncMeetingLink(one("SELECT * FROM psy_bookings WHERE id=?", [$b['id']]));
  /* сбрасываем отметки напоминаний нового специалиста */
  q("DELETE FROM psy_sent WHERE booking_id=? AND kind IN ('e2','eask','rep')", [$b['id']]);

  /* Специалисту не говорим «вас заменили» и не называем причину: для него это просто
     отменённая запись и снова свободное время. Полная картина — в кабинете у кураторов. */
  if($old && $old['tg_chat'])
    send($old['tg_chat'], "❌ Запись отменена\n" . fmtRu($nb['dt']) . "\nКлиент: " . $nb['client_name'] .
      "\nВаше время снова свободно и вернулось в календарь.");
  tell($new['tg_chat'], $new['email'] ?? '', 'Вам передали встречу — ' . fmtRu($nb['dt']),
    "↔️ Вам передали встречу\n" . fmtRu($nb['dt']) . "\n\nКлиент: " . $nb['client_name'] .
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
/* ── База клиентов ──────────────────────────────────────────
   Отдельной таблицы людей у нас нет и не нужно: человек — это его заявки.
   Склеиваем их по любому совпавшему каналу связи (телефон, телеграм, почта),
   поэтому две записи одного человека с разных устройств собираются в одну карточку. */
function normPhone($v){
  $d = preg_replace('/\D+/', '', (string)$v);
  if(strlen($d) === 11 && ($d[0] === '8' || $d[0] === '7')) $d = substr($d, 1);
  return strlen($d) >= 10 ? substr($d, -10) : '';
}
function normTg($v){
  $t = strtolower(trim((string)$v));
  $t = preg_replace('~^(https?://)?(t\.me/|telegram\.me/)~', '', $t);
  $t = ltrim($t, '@');
  return preg_match('/^[a-z0-9_]{4,32}$/', $t) ? $t : '';
}
function normMail($v){
  $m = strtolower(trim((string)$v));
  return filter_var($m, FILTER_VALIDATE_EMAIL) ? $m : '';
}
/* В старых заявках всё лежало одной строкой «связь» — разбираем, что там */
function contactKeys($row){
  $k = ['phone' => [], 'tg' => [], 'mail' => []];
  if($p = normPhone($row['phone'] ?? '')) $k['phone'][] = $p;
  if($t = normTg($row['tg_nick'] ?? '')) $k['tg'][] = $t;
  if($m = normMail($row['email'] ?? '')) $k['mail'][] = $m;
  $free = trim((string)($row['contact'] ?? ''));
  if($free !== ''){
    foreach(preg_split('/[\s,;]+/', $free) as $piece){
      if($m = normMail($piece)){ $k['mail'][] = $m; continue; }
      if($piece !== '' && ($piece[0] === '@' || stripos($piece, 't.me/') !== false)){
        if($t = normTg($piece)) $k['tg'][] = $t;
        continue;
      }
      if($p = normPhone($piece)) $k['phone'][] = $p;
    }
  }
  foreach($k as &$v) $v = array_values(array_unique(array_filter($v)));
  return $k;
}
/* Собирает заявки в людей. Возвращает массив карточек, свежие сверху. */
function clientBase(){
  $rows = all("SELECT b.*, e.name ename FROM psy_bookings b
               LEFT JOIN psy_experts e ON e.id=b.expert_id ORDER BY b.dt");
  $owner = [];                       /* ключ канала → номер группы */
  $group = [];                       /* номер группы → карточка */
  $find  = function($g) use (&$group){ while(isset($group[$g]['alias'])) $g = $group[$g]['alias']; return $g; };

  foreach($rows as $r){
    $keys = contactKeys($r);
    $flat = [];
    foreach($keys['phone'] as $v) $flat[] = 'p:' . $v;
    foreach($keys['tg']    as $v) $flat[] = 't:' . $v;
    foreach($keys['mail']  as $v) $flat[] = 'm:' . $v;

    $hit = null;
    foreach($flat as $k) if(isset($owner[$k])){ $hit = $find($owner[$k]); break; }
    if($hit === null){
      $hit = count($group);
      $group[$hit] = ['names' => [], 'phone' => [], 'tg' => [], 'mail' => [], 'bookings' => []];
    }
    /* заявка может связать две ранее разные группы — сливаем их */
    foreach($flat as $k){
      if(!isset($owner[$k])){ $owner[$k] = $hit; continue; }
      $other = $find($owner[$k]);
      if($other === $hit) continue;
      foreach(['names','phone','tg','mail','bookings'] as $f)
        $group[$hit][$f] = array_merge($group[$hit][$f], $group[$other][$f]);
      $group[$other] = ['alias' => $hit];
    }
    $g = &$group[$hit];
    if(trim((string)$r['client_name']) !== '') $g['names'][] = trim($r['client_name']);
    $g['phone'] = array_merge($g['phone'], $keys['phone']);
    $g['tg']    = array_merge($g['tg'],    $keys['tg']);
    $g['mail']  = array_merge($g['mail'],  $keys['mail']);
    $g['bookings'][] = $r;
    unset($g);
  }

  $out = [];
  foreach($group as $i => $g){
    if(isset($g['alias'])) continue;
    usort($g['bookings'], function($a, $b){ return strcmp($b['dt'], $a['dt']); });   /* свежие сверху */
    $last = $g['bookings'][0];
    $names = array_values(array_unique($g['names']));
    $csat  = [];
    $done = $cancel = $noshow = $ahead = $await = 0;
    $bk = [];
    foreach($g['bookings'] as $b){
      $st = $b['status'];
      if($st === 'cancelled') $cancel++;
      elseif($st === 'noshow') $noshow++;
      elseif($st === 'held' && $b['rep_at']) $done++;
      elseif(ts($b['dt']) < time()) $await++;
      else $ahead++;
      if($b['csat'] !== null && $b['csat'] !== '') $csat[] = (int)$b['csat'];
      $bk[] = ['code' => $b['code'], 'dt' => $b['dt'], 'status' => $b['status'], 'rep_at' => $b['rep_at'],
               'expert' => $b['ename'], 'category' => $b['category'], 'csat' => $b['csat'],
               'comment' => $b['comment'], 'pair' => (int)$b['pair'], 'utm' => $b['utm_source']];
    }
    $out[] = [
      'id'     => 'c' . $i,
      'name'   => $names ? $names[0] : 'Без имени',
      'names'  => $names,
      'phone'  => array_values(array_unique($g['phone'])),
      'tg'     => array_values(array_unique($g['tg'])),
      'mail'   => array_values(array_unique($g['mail'])),
      'total'  => count($g['bookings']),
      'done'   => $done, 'cancelled' => $cancel, 'noshow' => $noshow, 'ahead' => $ahead, 'await' => $await,
      'first'  => $g['bookings'][count($g['bookings']) - 1]['dt'],
      'last'   => $last['dt'],
      'lastExpert' => $last['ename'],
      'csat'   => $csat ? round(array_sum($csat) / count($csat), 1) : null,
      'source' => $last['utm_source'],
      'bookings' => $bk,
    ];
  }
  usort($out, function($a, $b){ return strcmp($b['last'], $a['last']); });
  return $out;
}

/* Сколько времени у клиента остаётся на перенос после начала встречи.
   Специалист может не подключиться — из-за интернета, накладки, чего угодно;
   если запереть перенос ровно в час начала, клиент останется ни с чем. */
const PSY_LATE_HOURS = 24;
function clientCanMove($b){
  if(in_array($b['status'], ['cancelled', 'held', 'noshow'], true)) return false;  /* исход уже известен */
  if(!empty($b['rep_at'])) return false;                                           /* специалист отчитался */
  return time() < ts($b['dt']) + PSY_LATE_HOURS * 3600;
}
function lateMoveNote($b){
  return time() > ts($b['dt'])
    ? 'Встреча уже началась. Если специалист не подключился — перенесите её на другое время.'
    : '';
}

function rescheduleBooking($b, $when, $by){
  if(!is_array($by)) $by = actor('curator');
  $who = actorLabel($by);
  if(!$b || $b['status'] === 'cancelled') return null;
  $pdo   = db();
  $pdo->beginTransaction();
  try{
    $cands = all(
      "SELECT s.id, s.expert_id FROM psy_slots s
         JOIN psy_experts e ON e.id = s.expert_id
        WHERE s.dt = ? AND s.booking_id IS NULL AND e.active = 1
        ORDER BY s.id FOR UPDATE", [$when]);
    /* сначала пробуем сохранить того же специалиста — клиентка уже настроилась на него */
    $best = null;
    foreach($cands as $c){
      if((int)$c['expert_id'] !== (int)$b['expert_id']) continue;
      $best = $c; break;
    }
    /* при переносе — тот же порядок: без подтверждения и без своей комнаты
       специалист в подбор не попадает */
    if($best && (!rulesOk($best['expert_id']) || !ownRoom(expert($best['expert_id'])))) $best = null;
    if(!$best){
      /* Другой специалист — только тот, кто подошёл бы и при обычной записи:
         та же тема, тот же формат, тот же пол. Раньше здесь брали любого свободного,
         и перенос приводил клиентку к специалисту, который с её запросом не работает. */
      $bl = PHP_INT_MAX;
      foreach($cands as $c){
        $ex = expert($c['expert_id']);
        if(!$ex || !rulesOk($ex)) continue;
        if(!ownRoom($ex)) continue;
        if(!worksWith($ex, (string)$b['category'])) continue;
        if(!empty($b['pair'])){ if(!takesPairs($ex) || gendersOf($ex) !== 'any') continue; }
        elseif(!worksGender($ex, (string)($b['gender'] ?? ''))) continue;
        $l = loadOf($c['expert_id']);
        if($l >= quotaOf($ex)) continue;
        $key = priorityOf($ex) * 10000 + $l;          /* как при записи: сначала приоритет, потом загрузка */
        if($key < $bl){ $bl = $key; $best = $c; }
      }
    }
    if(!$best){ $pdo->rollBack(); return null; }

    $oldExpert = (int)$b['expert_id'];
    $oldDt     = $b['dt'];
    q("UPDATE psy_slots SET booking_id=? WHERE id=?", [$b['id'], $best['id']]);
    q("UPDATE psy_slots SET booking_id=NULL WHERE id=? AND booking_id=?", [$b['slot_id'], $b['id']]);
    $e    = expert($best['expert_id']);
    $link = ((int)$best['expert_id'] === $oldExpert && $b['link'] !== '') ? $b['link'] : meetingLink($e, $b['code']);
    q("UPDATE psy_bookings SET slot_id=?, expert_id=?, dt=?, link=?, status='booked' WHERE id=?",
      [$best['id'], $best['expert_id'], $when, $link, $b['id']]);
    $pdo->commit();
  }catch(Throwable $ex){
    if($pdo->inTransaction()) $pdo->rollBack();
    logit('resched', $ex->getMessage());
    return null;
  }

  /* напоминания едут заново — время другое */
  q("DELETE FROM psy_sent WHERE booking_id=? AND kind IN ('r24','r2','e2','eask','ask','rep')", [$b['id']]);
  $t = ts($when);
  if(time() > $t - 24*3600) markSent($b['id'], 'r24', 'skip');
  if(time() > $t - 2*3600){ markSent($b['id'], 'r2', 'skip'); markSent($b['id'], 'e2', 'skip'); }

  $nb = syncMeetingLink(one("SELECT * FROM psy_bookings WHERE id=?", [$b['id']]));
  $ne = expert($nb['expert_id']);
  $oldEx   = expert($oldExpert);
  $oldName = $oldEx['name'] ?? '—';
  if($nb['tg_chat'])
    send($nb['tg_chat'], "🔄 Встреча перенесена\nБыло: " . fmtRu($oldDt) . "\nСтало: " . fmtRu($nb['dt']) .
      "\nСпециалист: " . $ne['name'] . "\nСсылка: " . ($nb['link'] ?: 'пришлём отдельно') .
      (($by['who'] ?? '') !== 'client' ? "\nКто перенёс: " . $who : ''));
  /* Кто перенёс — говорим только когда это сам клиент: это нейтральный факт и он
     объясняет специалисту, откуда изменение. Внутренние решения кураторов
     специалисту не пересказываем, они видны кураторам в истории записи. */
  $byClient = (($by['who'] ?? '') === 'client');
  if($ne['tg_chat']){
    if($oldExpert !== (int)$nb['expert_id'])
      send($ne['tg_chat'], "🔔 Вам передали встречу\n" . fmtRu($nb['dt']) .
        "\nКлиент: " . $nb['client_name'] .
        "\nСсылка: " . ($nb['link'] ?: 'пришлём отдельно'));
    else
      send($ne['tg_chat'], ($byClient ? "🔄 Клиент перенёс встречу" : "🔄 Встреча перенесена") .
        "\nБыло: " . fmtRu($oldDt) . "\nСтало: " . fmtRu($nb['dt']) .
        "\nКлиент: " . $nb['client_name']);
  }
  if($oldExpert !== (int)$nb['expert_id']){
    $oe = $oldEx;
    if($oe && $oe['tg_chat'])
      send($oe['tg_chat'], ($byClient ? "❌ Клиент перенёс встречу на другое время"
                                      : "❌ Запись отменена") .
        "\n" . fmtRu($oldDt) . "\nКлиент: " . $nb['client_name'] .
        "\nВаше время снова свободно и вернулось в календарь.");
  }
  histAdd($nb['id'], 'move', 'Перенос: ' . fmtRu($oldDt) . ' → ' . fmtRu($nb['dt']) .
          ($oldExpert !== (int)$nb['expert_id']
            ? '. Специалист сменился: ' . $oldName . ' → ' . $ne['name'] : ''), $by);
  adminNotify("🔄 Перенос: " . $nb['client_name'] . "\n" . fmtRu($oldDt) . " → " . fmtRu($nb['dt']) .
              "\nСпециалист: " . $ne['name'] . "\nКто перенёс: " . $who);

  /* Клиентка переносит встречу, час которой уже наступил, — почти всегда это значит,
     что специалист не подключился. Считаем это срывом и говорим кураторам. */
  if($byClient && time() > ts($oldDt)) noticeMissed($oldExpert, $nb, $oldDt);
  return $nb;
}

function cancelBooking($b, $by){
  if(!is_array($by)) $by = actor('curator');
  $who = actorLabel($by);
  if(!$b || $b['status'] === 'cancelled') return false;
  q("UPDATE psy_bookings SET status='cancelled' WHERE id=?", [$b['id']]);
  q("UPDATE psy_slots SET booking_id=NULL WHERE id=? AND booking_id=?", [$b['slot_id'], $b['id']]);
  $e = expert($b['expert_id']);
  if($e && $e['tg_chat'])
    send($e['tg_chat'], ((($by['who'] ?? '') === 'client') ? "❌ Клиент отменил запись" : "❌ Запись отменена") .
      "\n" . fmtRu($b['dt']) . "\nКлиент: " . $b['client_name'] .
      "\nВаше время снова свободно и открыто для записи.");
  histAdd($b['id'], 'cancel', 'Отмена встречи', $by);

  /* Клиента предупреждаем всегда, кроме случая, когда он отменил сам:
     человек не должен узнать об отмене, придя на встречу. Бот — если подключён,
     иначе SMS, иначе почта. */
  if(($by['who'] ?? '') !== 'client'){
    $txt = "❌ Встреча отменена\n" . fmtRu($b['dt']) .
           "\nСпециалист: " . ($e['name'] ?? '—') .
           "\n\nЕсли захотите вернуться, выберите новое время: " . siteUrl();
    $sms = 'Встреча ' . date('d.m', ts($b['dt'])) . ' в ' . date('H:i', ts($b['dt'])) . tzShort() .
           ' отменена. Новое время: ' . preg_replace('~^https?://~', '', siteUrl());
    $via = tell($b['tg_chat'], $b['email'], 'Встреча отменена — ' . fmtRu($b['dt']), $txt, true,
                bookingPhone($b), $sms);
    markSent($b['id'], 'canc', $via ?: 'none');
    if($via){
      histAdd($b['id'], 'notify', 'Клиентке сообщили об отмене (' .
        ['tg'=>'Telegram','sms'=>'SMS','mail'=>'почта'][$via] . ')', actor('system'));
    }else{
      $why = trim((string)($GLOBALS['PSY_SMS_ERR'] ?? ''));
      histAdd($b['id'], 'notify', 'Сообщить клиентке об отмене не удалось' .
        (bookingPhone($b) !== '' && $why !== '' ? ': SMS не ушла — ' . $why
                                                : ' — нет ни бота, ни телефона, ни почты') .
        '. Свяжитесь с ней вручную.', actor('system'));
    }
  }

  adminNotify("❌ Отмена ($who): " . $b['client_name'] . ", " . fmtRu($b['dt']) . ", " . ($e['name'] ?? '—'));
  return true;
}

/* ── Очередь сообщений (реле забирает и шлёт в Telegram) ────── */
function send($chat, $text, $kb = null){
  if(!$chat) return false;
  q("INSERT INTO psy_outbox(chat,text,kb,status,created_at) VALUES(?,?,?, 'pending', ?)",
    [$chat, $text, $kb ? json_encode($kb, JSON_UNESCAPED_UNICODE) : null, now()]);
  return true;
}
/* Первое открытое время — значит, в потоке появился новый работающий специалист.
   Кураторам это важно увидеть сразу: успеть проверить темы и подключить бота. */
function isTestCard($e){ return !empty($e['is_test']); }
/* Договорённости приняты? Пока проверка выключена, отвечаем «да» всем. */
function rulesGate(){ return cfg('rules_gate') === '1'; }
function rulesOk($e){
  if(!rulesGate()) return true;
  if(is_numeric($e)) $e = expert($e);
  return !empty($e['rules_at']);
}
function noticeFirstSlots($expertId){
  $e = expert($expertId);
  if(!$e || !empty($e['first_slots_at'])) return false;
  if(isTestCard($e)){                      /* тестовая карточка кураторов не беспокоит */
    q("UPDATE psy_experts SET first_slots_at=? WHERE id=? AND first_slots_at IS NULL", [now(), (int)$expertId]);
    return false;
  }
  /* ставим отметку одним запросом с условием: если два запроса пришли разом, уведомление уйдёт один раз */
  $st = q("UPDATE psy_experts SET first_slots_at=? WHERE id=? AND first_slots_at IS NULL", [now(), (int)$expertId]);
  if(!$st->rowCount()) return false;

  $slots = all("SELECT dt FROM psy_slots WHERE expert_id=? ORDER BY dt", [(int)$expertId]);
  $topics = topicsOf($e);
  adminNotify("🆕 Новый специалист открыл время
" .
    $e['name'] . ($e['direction'] ? "
" . $e['direction'] : '') .
    "
Слотов открыто: " . count($slots) .
    ($slots ? "
Первое время: " . fmtRu($slots[0]['dt']) : '') .
    "
Темы: " . ($topics ? implode(', ', $topics) : 'не отмечены — возьмёт любую') .
    "
Парные: " . (takesPairs($e) ? 'берёт' : 'нет') .
    "
Telegram: " . ($e['tg_chat'] ? 'подключён' : 'НЕ подключён — уведомления не дойдут') .
    "
Квота: " . quotaOf($e) . ", приоритет: " . priorityName(priorityOf($e)));
  return true;
}
/* Срыв встречи специалистом: фиксируем, считаем и зовём людей.
   Автоматически никого не отключаем — решение о человеке принимает человек. */
function noticeMissed($expertId, $b, $oldDt){
  $e = expert($expertId);
  if(!$e || isTestCard($e)) return;
  histAdd($b['id'], 'missed', 'Специалиста не было на встрече ' . fmtRu($oldDt) .
          ': клиентка перенесла её уже после начала', actor('system'));
  $n = missedCount($expertId);
  adminNotify(($n >= 2 ? "🚨 Второй срыв" : "⚠️ Срыв встречи") . "\n" .
    ($e['name'] ?? '—') . " — не была на встрече " . fmtRu($oldDt) . "\n" .
    "Клиентка " . $b['client_name'] . " перенесла её сама на " . fmtRu($b['dt']) . ".\n\n" .
    ($n >= 2
      ? "Это уже " . $n . "-й случай за 30 дней. По правилам — разговор и пауза в распределении."
      : "Первый случай за 30 дней. Стоит спросить, что произошло."));
  if($e['tg_chat'])
    send($e['tg_chat'], "❗️ Клиентка " . $b['client_name'] . " не дождалась вас на встрече " . fmtRu($oldDt) .
      " и перенесла её на " . fmtRu($b['dt']) . ".\n\n" .
      "Если не можете провести встречу — предупредите заранее: снимите время в кабинете или напишите координатору. " .
      "Клиентка, которая осталась одна перед экраном, обычно не возвращается.");
}
/* Сколько срывов у специалиста за последние 30 дней */
function missedCount($expertId){
  return (int)one("SELECT COUNT(*) c FROM psy_history h
                     JOIN psy_bookings b ON b.id = h.booking_id
                    WHERE h.kind='missed' AND b.expert_id=? AND h.at > ?",
                  [(int)$expertId, date('Y-m-d H:i:s', time() - 30*86400)])['c'];
}
/* Отметить заход. step: open — открыли страницу, time — дошли до календаря,
   form — дошли до формы контактов. Ничего, кроме дня, источника и счётчика. */
function hitVisit($source, $step){
  $step = in_array($step, ['open', 'time', 'form'], true) ? $step : 'open';
  $src  = mb_substr(preg_replace('/[^a-zA-Z0-9_\-]/', '', (string)$source), 0, 40);
  q("INSERT INTO psy_visits(d, source, step, n) VALUES(?,?,?,1)
     ON DUPLICATE KEY UPDATE n = n + 1", [date('Y-m-d'), $src, $step]);
}

/* ── Сводка для вкладки «Статистика» ──────────────────────────
   Считаем ровно теми же правилами, что и дашборд: состоявшейся считается
   встреча со сданным отчётом. */
function statsFor($from, $to){
  $rows = all("SELECT * FROM psy_bookings WHERE dt BETWEEN ? AND ? ORDER BY dt", [$from . ' 00:00:00', $to . ' 23:59:59']);
  $made = (int)one("SELECT COUNT(*) c FROM psy_bookings WHERE created_at BETWEEN ? AND ?",
                   [$from . ' 00:00:00', $to . ' 23:59:59'])['c'];
  $t = ['made' => $made, 'total' => 0, 'done' => 0, 'noshow' => 0, 'cancelled' => 0, 'await' => 0, 'ahead' => 0];
  $weeks = [];
  foreach($rows as $b){
    $st = ($b['status'] === 'cancelled') ? 'cancelled'
        : (($b['status'] === 'noshow') ? 'noshow'
        : (($b['status'] === 'held' && $b['rep_at']) ? 'done'
        : (ts($b['dt']) < time() ? 'await' : 'ahead')));
    $t['total']++; $t[$st]++;
    $w = date('o-W', ts($b['dt']));
    if(!isset($weeks[$w])) $weeks[$w] = ['week' => $w, 'from' => date('d.m', strtotime(substr($w,0,4) . 'W' . substr($w,5) . '1')),
                                         'to' => date('d.m', strtotime(substr($w,0,4) . 'W' . substr($w,5) . '7')),
                                         'made' => 0, 'total' => 0, 'done' => 0, 'noshow' => 0, 'cancelled' => 0, 'await' => 0, 'ahead' => 0];
    $weeks[$w]['total']++; $weeks[$w][$st]++;
  }
  foreach(all("SELECT created_at FROM psy_bookings WHERE created_at BETWEEN ? AND ?",
              [$from . ' 00:00:00', $to . ' 23:59:59']) as $b){
    $w = date('o-W', ts($b['created_at']));
    if(!isset($weeks[$w])) $weeks[$w] = ['week' => $w, 'from' => date('d.m', strtotime(substr($w,0,4) . 'W' . substr($w,5) . '1')),
                                         'to' => date('d.m', strtotime(substr($w,0,4) . 'W' . substr($w,5) . '7')),
                                         'made' => 0, 'total' => 0, 'done' => 0, 'noshow' => 0, 'cancelled' => 0, 'await' => 0, 'ahead' => 0];
    $weeks[$w]['made']++;
  }
  ksort($weeks);

  $src = all("SELECT COALESCE(NULLIF(utm_source,''),'без метки') s, COUNT(*) c FROM psy_bookings
               WHERE created_at BETWEEN ? AND ? GROUP BY s ORDER BY c DESC", [$from . ' 00:00:00', $to . ' 23:59:59']);
  $visits = all("SELECT step, COALESCE(NULLIF(source,''),'без метки') s, SUM(n) n FROM psy_visits
                  WHERE d BETWEEN ? AND ? GROUP BY step, s", [$from, $to]);
  $funnel = ['open' => 0, 'time' => 0, 'form' => 0];
  $bySrc = [];
  foreach($visits as $v){
    $funnel[$v['step']] = ($funnel[$v['step']] ?? 0) + (int)$v['n'];
    if(!isset($bySrc[$v['s']])) $bySrc[$v['s']] = ['source' => $v['s'], 'open' => 0, 'time' => 0, 'form' => 0];
    $bySrc[$v['s']][$v['step']] += (int)$v['n'];
  }
  $csat = one("SELECT COUNT(*) n, ROUND(AVG(csat),1) a FROM psy_bookings
                WHERE csat IS NOT NULL AND dt BETWEEN ? AND ?", [$from . ' 00:00:00', $to . ' 23:59:59']);
  $first = one("SELECT MIN(d) d FROM psy_visits");

  /* Список месяцев, за которые вообще есть данные — чтобы координатор выбирал из готового */
  $start = one("SELECT MIN(LEAST(created_at, dt)) m FROM psy_bookings")['m'] ?? date('Y-m-01');
  $months = [];
  $cur = strtotime(date('Y-m-01', ts($start)));
  $end = strtotime(date('Y-m-01'));
  while($cur <= $end){ $months[] = date('Y-m', $cur); $cur = strtotime('+1 month', $cur); }
  $months = array_reverse($months);

  return ['from' => $from, 'to' => $to, 'months' => $months, 'firstDay' => substr((string)$start, 0, 10),
          'sum' => $t, 'weeks' => array_values($weeks),
          'sources' => $src, 'funnel' => $funnel, 'bySource' => array_values($bySrc),
          'csat' => ['n' => (int)$csat['n'], 'avg' => $csat['a']],
          'visitsSince' => $first['d'] ?? null];
}

function adminNotify($text){
  foreach(all("SELECT chat FROM psy_tg WHERE role='admin'") as $r) send($r['chat'], $text);
}
function markSent($bid, $kind, $via = ''){
  q("INSERT IGNORE INTO psy_sent(booking_id,kind,at,via) VALUES(?,?,?,?)", [$bid, $kind, now(), $via]);
}
function wasSent($bid, $kind){
  return (bool)one("SELECT kind FROM psy_sent WHERE booking_id=? AND kind=?", [$bid, $kind]);
}
require_once __DIR__ . '/faq.php';

function botLink($payload){
  $u = cfg('bot_user');
  return $u ? "https://t.me/$u?start=$payload" : '';
}

function notifyNewBooking($b, $e){
  $txt = "🔔 Новая запись" . (!empty($b['pair']) ? " · ПАРНАЯ" : "") . "\n" . fmtRu($b['dt']) . "\n\n" .
    "Клиент: " . $b['client_name'] . "\n" .
    (!empty($b['phone'])   ? "Телефон: " . $b['phone'] . "\n" : '') .
    (!empty($b['tg_nick']) ? "Telegram: @" . $b['tg_nick'] . "\n" : '') .
    (empty($b['phone']) && empty($b['tg_nick']) ? "Связь: " . $b['contact'] . "\n" : '') .
    "Тема: " . $b['category'] . ($b['note'] ? "\n«" . $b['note'] . "»" : '') . "\n\n" .
    "Ссылка на встречу: " . ($b['link'] ?: 'не задана — добавьте комнату в кабинете');
  tell($e['tg_chat'], $e['email'] ?? '', 'Новая запись — ' . fmtRu($b['dt']), $txt, true);
  adminNotify("🔔 Запись: " . $b['client_name'] . " → " . $e['name'] . ", " . fmtRu($b['dt']) .
              "\nЗапрос: " . $b['category'] . "\nСвязь: " . $b['contact']);
}

/* ── Телеграм: обработка входящих (логика на сервере) ───────── */
/* Кураторский доступ по ключу получает только чат, не привязанный к специалисту.
   Специалист, открывший кураторскую ссылку, иначе молча начинал получать
   записи и телефоны чужих клиенток. Такой доступ выдаёт владелец в кабинете. */
function refuseAdminForExpert($chat){
  $ex = one("SELECT name FROM psy_experts WHERE tg_chat=?", [(string)$chat]);
  if(!$ex) return false;
  send($chat, "Этот Telegram подключён как кабинет специалиста (" . $ex['name'] . "), поэтому кураторскую ссылку я не приняла.\n\n" .
    "Уведомления о ваших записях продолжают приходить сюда как раньше. Если вам действительно нужен доступ куратора — его выдаёт владелец.");
  adminNotify("⚠️ Специалист " . $ex['name'] . " открыла кураторскую ссылку — доступ не выдан. Похоже, ссылка разошлась по людям.");
  logit('tg', 'кураторская ссылка у специалиста: ' . $ex['name']);
  return true;
}
function tgBind($chat, $role, $expertId = null, $bookingId = null, $state = ''){
  q("INSERT INTO psy_tg(chat,role,expert_id,booking_id,state,updated_at) VALUES(?,?,?,?,?,?)
     ON DUPLICATE KEY UPDATE role=VALUES(role), expert_id=VALUES(expert_id),
       booking_id=VALUES(booking_id), state=VALUES(state), updated_at=VALUES(updated_at)",
    [$chat, $role, $expertId, $bookingId, $state, now()]);
}
function tgState($chat){ return one("SELECT * FROM psy_tg WHERE chat=?", [$chat]); }
function setState($chat, $state, $bookingId = null){
  q("UPDATE psy_tg SET state=?, booking_id=COALESCE(?,booking_id), updated_at=? WHERE chat=?",
    [$state, $bookingId, now(), $chat]);
}

function kbRow(...$btns){ return $btns; }
function ikb($rows){ return ['inline_keyboard' => $rows]; }
function btn($text, $data){ return ['text' => $text, 'callback_data' => $data]; }

function clientCard($b){
  $e = expert($b['expert_id']);
  return "✅ Вы записаны\n\n" .
    "🗓 " . fmtRu($b['dt']) . "\n" .
    "👤 Специалист: " . ($e['name'] ?? '—') . "\n" .
    "🔗 Ссылка: " . ($b['link'] ?: 'координатор пришлёт отдельно') . "\n" .
    "🔑 Код записи: " . $b['code'] . "\n\n" .
    "Напомним за сутки и за два часа. Если время не подходит — перенесите или отмените, слот сразу освободится для другой.";
}
function faqMenuText($role = 'expert'){
  if($role === 'admin')
    return "Спросите меня своими словами — отвечу про кабинет координатора и про работу потока.\n\n" .
           "Сверху в списке — ваши темы, ниже — то, что бот отвечает специалистам: удобно свериться, " .
           "чтобы говорить с ними одними словами.";
  return "Спросите меня своими словами — отвечу по правилам школы и по работе агрегатора.\n\n" .
         "Или выберите тему из списка:";
}
function faqMenuKb($role = 'expert'){
  $rows = [];
  $pair = [];
  foreach(FAQ($role) as $item){
    $pair[] = btn($item['title'], 'faq:' . $item['id']);
    if(count($pair) === 2){ $rows[] = kbRow($pair[0], $pair[1]); $pair = []; }
  }
  if($pair) $rows[] = kbRow($pair[0]);
  $rows[] = kbRow(btn('Задать свой вопрос куратору', 'faq:ask'));
  return ikb($rows);
}

function clientKb($b){
  return ikb([kbRow(btn('Перенести', 'move:' . $b['id']), btn('Отменить запись', 'cancel:' . $b['id']))]);
}
function siteUrl(){
  $u = cfg('site_url');
  return $u ?: (defined('SITE_URL') ? SITE_URL : 'https://booking.example.com') . '/zapis/';
}

function rememberWho($chat, $from){
  if(!$chat || !is_array($from)) return;
  $un = mb_substr((string)($from['username'] ?? ''), 0, 60);
  $fn = trim(mb_substr((string)($from['first_name'] ?? ''), 0, 60) . ' ' . mb_substr((string)($from['last_name'] ?? ''), 0, 60));
  q("UPDATE psy_tg SET uname=?, fname=? WHERE chat=?", [$un, $fn, $chat]);
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

    /* кнопки базы ответов для специалистов */
    if($p[0] === 'faq' && isset($p[1])){
      $cst  = tgState($chat);
      $crole = ($cst && $cst['role'] === 'admin') ? 'admin' : 'expert';
      if($p[1] === 'menu'){ send($chat, faqMenuText($crole), faqMenuKb($crole)); return; }
      if($p[1] === 'ask'){
        setState($chat, 'ask');
        send($chat, "Напишите вопрос одним сообщением — передам координатору.");
        return;
      }
      $item = faqById($p[1], $crole);
      /* Кнопка из чужого меню — например, осталась в переписке с тех пор, как чат
         по ошибке был кураторским. Не молчим, а показываем меню текущей роли. */
      if(!$item){ send($chat, "Эта кнопка из другого меню. Вот ваши темы:", faqMenuKb($crole)); return; }
      if($item){
        send($chat, $item['text'], $crole === 'admin'
          ? ikb([kbRow(btn('Все вопросы', 'faq:menu'))])
          : ikb([kbRow(btn('Все вопросы', 'faq:menu'), btn('Спросить куратора', 'faq:ask'))]));
        logit('faq', 'кнопка «' . $item['id'] . '»');
      }
      return;
    }

    if($p[0] === 'csat' && isset($p[1], $p[2])){
      $b = one("SELECT * FROM psy_bookings WHERE id=?", [(int)$p[1]]);
      if($b){
        q("UPDATE psy_bookings SET csat=?, csat_at=? WHERE id=?", [(int)$p[2], now(), $b['id']]);
        setState($chat, 'fit', $b['id']);
        send($chat, "Спасибо! Оценка: " . (int)$p[2] . " из 5.\n\nСпециалист вам подошёл?", ikb([
          kbRow(btn('Да', 'fit:' . $b['id'] . ':Да'), btn('Скорее да', 'fit:' . $b['id'] . ':Скорее да')),
          kbRow(btn('Скорее нет', 'fit:' . $b['id'] . ':Скорее нет'), btn('Нет', 'fit:' . $b['id'] . ':Нет')),
        ]));
        if((int)$p[2] <= 3){
          $e = expert($b['expert_id']);
          adminNotify("⚠️ Низкая оценка " . (int)$p[2] . "/5\n" . $b['client_name'] . " → " . ($e['name'] ?? '—') .
                      ", " . fmtRu($b['dt']) . "\nСтоит позвонить клиенту сегодня.");
        }
      }
      return;
    }
    if($p[0] === 'fit' && isset($p[1], $p[2])){
      $b = one("SELECT * FROM psy_bookings WHERE id=?", [(int)$p[1]]);
      if($b){
        q("UPDATE psy_bookings SET fit=? WHERE id=?", [$p[2], $b['id']]);
        setState($chat, 'nps', $b['id']);
        $rows = [];
        for($i = 0; $i <= 10; $i += 4){
          $r = [];
          for($j = $i; $j < min($i + 4, 11); $j++) $r[] = btn((string)$j, 'nps:' . $b['id'] . ':' . $j);
          $rows[] = $r;
        }
        send($chat, "Насколько вероятно, что порекомендуете нас подруге? 0 — точно нет, 10 — точно да.", ikb($rows));
      }
      return;
    }
    if($p[0] === 'nps' && isset($p[1], $p[2])){
      $b = one("SELECT * FROM psy_bookings WHERE id=?", [(int)$p[1]]);
      if($b){
        q("UPDATE psy_bookings SET nps=? WHERE id=?", [(int)$p[2], $b['id']]);
        setState($chat, 'comment', $b['id']);
        send($chat, "Последнее: напишите пару слов своими словами — что было полезно, чего не хватило.\nЕсли добавить нечего, отправьте «-».");
      }
      return;
    }
    if($p[0] === 'cancel' && isset($p[1])){
      $b = one("SELECT * FROM psy_bookings WHERE id=?", [(int)$p[1]]);
      if($b && $b['status'] !== 'cancelled'){
        send($chat, "Точно отменить запись на " . fmtRu($b['dt']) . "?", ikb([
          kbRow(btn('Да, отменить', 'cancelyes:' . $b['id']), btn('Нет, оставить', 'cancelno:' . $b['id'])),
        ]));
      }
      return;
    }
    if($p[0] === 'move' && isset($p[1])){
      $b = one("SELECT * FROM psy_bookings WHERE id=?", [(int)$p[1]]);
      if($b && $b['status'] !== 'cancelled'){
        send($chat, "Перенос — на странице записи: там видно, какие времена свободны сейчас.\n\n" .
          siteUrl() . "?code=" . $b['code'] . "\n\nСтарое время держим за вами, пока вы не выберете новое.");
      }
      return;
    }
    if($p[0] === 'cancelno'){ send($chat, "Хорошо, запись в силе. До встречи!"); return; }
    if($p[0] === 'cancelyes' && isset($p[1])){
      $b = one("SELECT * FROM psy_bookings WHERE id=?", [(int)$p[1]]);
      if($b && cancelBooking($b, actor('client', '', 'bot'))) send($chat, "Запись отменена. Если захотите вернуться — выберите новое время на странице записи.");
      return;
    }
    if($p[0] === 'conf' && isset($p[1], $p[2])){
      $b = one("SELECT * FROM psy_bookings WHERE id=?", [(int)$p[1]]);
      if($b){
        $val = $p[2] === 'yes' ? 'yes' : 'no';
        q("UPDATE psy_bookings SET confirm=?, confirm_at=? WHERE id=?", [$val, now(), $b['id']]);
        $e = expert($b['expert_id']);
        histAdd($b['id'], 'confirm', $val === 'yes'
          ? 'Подтверждён приход клиента'
          : 'Не дозвонились до клиента', actor('expert', $e['name'] ?? '', 'bot'));
        if($val === 'yes') send($chat, "Спасибо, отметила. До встречи " . fmtRu($b['dt']) . ".");
        else{
          send($chat, "Отметила: клиент не отвечает. Координатор подключится — попробует достать его по другим каналам.");
          adminNotify("📵 Клиент не отвечает\n" . $b['client_name'] . ", " . fmtRu($b['dt']) .
            "\nСпециалист: " . ($e['name'] ?? '—') .
            (!empty($b['phone']) ? "\nТелефон: " . $b['phone'] : '') .
            (!empty($b['tg_nick']) ? "\nTelegram: @" . $b['tg_nick'] : '') .
            "\nСтоит попробовать связаться самой — иначе это будет неявка.");
        }
      }
      return;
    }
    /* специалист отменяет встречу — в два шага, чтобы не задеть случайно */
    if($p[0] === 'ecan' && isset($p[1])){
      $b = one("SELECT * FROM psy_bookings WHERE id=?", [(int)$p[1]]);
      if($b && $b['status'] !== 'cancelled'){
        send($chat, "Отменить встречу " . fmtRu($b['dt']) . " с " . $b['client_name'] . "?\n\n" .
          "Клиентке уйдёт сообщение об отмене, ваше время вернётся в общий пул.", ikb([
          kbRow(btn('Да, отменить', 'ecanyes:' . $b['id']), btn('Нет', 'ecanno:' . $b['id'])),
        ]));
      }
      return;
    }
    if($p[0] === 'ecanno'){ send($chat, "Хорошо, встреча остаётся в силе."); return; }
    if($p[0] === 'ecanyes' && isset($p[1])){
      $b = one("SELECT * FROM psy_bookings WHERE id=?", [(int)$p[1]]);
      $st = tgState($chat);
      $e  = ($st && $st['expert_id']) ? expert($st['expert_id']) : null;
      if(!$b || !$e || (int)$b['expert_id'] !== (int)$e['id']){ send($chat, "Это не ваша встреча."); return; }
      if($b['status'] === 'cancelled'){ send($chat, "Встреча уже отменена."); return; }
      if(cancelBooking($b, actor('expert', $e['name']))){
        send($chat, "Готово, встреча отменена. Клиентке сообщили, ваше время снова свободно.");
        adminNotify("❗️ Специалист отменил встречу\n" . $e['name'] . " — " . $b['client_name'] .
          ", " . fmtRu($b['dt']) . "\nКлиентке сообщение отправлено, слот вернулся в пул.");
      }
      return;
    }
    /* эксперт отмечает исход встречи */
    if($p[0] === 'held' && isset($p[1], $p[2])){
      $b = one("SELECT * FROM psy_bookings WHERE id=?", [(int)$p[1]]);
      if($b){
        $map = ['h' => 'held', 'n' => 'noshow', 'c' => 'cancelled'];
        $st  = $map[$p[2]] ?? 'held';
        q("UPDATE psy_bookings SET status=? WHERE id=?", [$st, $b['id']]);
        $e   = expert($b['expert_id']);
        $txt = ['held' => 'состоялась', 'noshow' => 'клиент не пришёл', 'cancelled' => 'отменена'][$st];
        histAdd($b['id'], 'result', 'Итог встречи: ' . $txt, actor('expert', $e['name'] ?? '', 'bot'));
        send($chat, "Отмечено: $txt.\n\nОсталось сдать короткий отчёт в кабинете — без него система не даёт новых клиентов.");
        if($st === 'noshow') adminNotify("🚫 Не пришёл: " . $b['client_name'] . " → " . ($e['name'] ?? '—') . ", " . fmtRu($b['dt']));
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
        if(refuseAdminForExpert($chat)) return;
        tgBind($chat, 'admin');
        rememberWho($chat, $m['from'] ?? null);
        send($chat, "✅ Готово, вы подключены как куратор.\n\nСюда пойдут все новые записи, отмены, неявки и оценки ниже 4.");
        return;
      }
      $ex = one("SELECT * FROM psy_experts WHERE tg_key=?", [$try]);
      if($ex){
        q("UPDATE psy_experts SET tg_chat=? WHERE id=?", [$chat, $ex['id']]);
        tgBind($chat, 'expert', $ex['id']);
        rememberWho($chat, $m['from'] ?? null);
        send($chat, "✅ Готово, " . $ex['name'] . ". Кабинет подключён — сюда пойдут новые записи и напоминания.");
        return;
      }
    }
  }

  if(strpos($text, '/start') === 0){
    $payload = trim(substr($text, 6));
    /* клиент: b<КОД> */
    if($payload && ($payload[0] === 'b' || $payload[0] === 'B')){
      $code = strtoupper(substr($payload, 1));
      $b = one("SELECT * FROM psy_bookings WHERE code=?", [$code]);
      if($b){
        q("UPDATE psy_bookings SET tg_chat=? WHERE id=?", [$chat, $b['id']]);
        /* координатора или специалиста не понижаем до клиента — иначе,
           проверив клиентскую ссылку со своего телефона, человек потеряет свою роль */
        $cur = tgState($chat);
        if($cur && in_array($cur['role'], ['admin', 'expert'], true))
          q("UPDATE psy_tg SET booking_id=?, updated_at=? WHERE chat=?", [$b['id'], now(), $chat]);
        else
          tgBind($chat, 'client', null, $b['id']);
        send($chat, clientCard($b), clientKb($b));
        return;
      }
      send($chat, "Не нашли запись по этому коду. Проверьте код из подтверждения или запишитесь заново на странице записи.");
      return;
    }
    /* эксперт: e<ключ> */
    if($payload && ($payload[0] === 'e' || $payload[0] === 'E')){
      $key = substr($payload, 1);
      $e = one("SELECT * FROM psy_experts WHERE tg_key=?", [$key]);
      if($e){
        q("UPDATE psy_experts SET tg_chat=? WHERE id=?", [$chat, $e['id']]);
        tgBind($chat, 'expert', $e['id']);
        send($chat, "Здравствуйте, " . $e['name'] . "! Кабинет подключён.\n\n" .
          "Сюда будут приходить:\n• новая запись — сразу\n• напоминание за 2 часа с данными клиента\n• после встречи — вопрос, состоялась ли она\n\n" .
          "Слоты и отчёты — в кабинете специалиста на сайте.\n\n" .
          "И ещё: спрашивайте меня о работе на агрегаторе своими словами — про оплату, клиентов, темы, отчёты. " .
          "Чего не знаю, передам координатору.", faqMenuKb());
        return;
      }
      send($chat, "Ключ не подошёл. Возьмите свежую ссылку у координатора.");
      return;
    }
    /* координатор: a<ключ> */
    if($payload && ($payload[0] === 'a' || $payload[0] === 'A')){
      if(substr($payload, 1) === cfg('admin_key')){
        if(refuseAdminForExpert($chat)) return;
        tgBind($chat, 'admin');
        send($chat, "Координатор подключён. Сюда пойдут: новые записи, отмены, неявки и все оценки ниже 4.\n\n" .
          "И спрашивайте меня своими словами — про кабинет, цифры на дашборде, смену специалиста, базу клиентов " .
          "и про то, что бот отвечает специалистам.", faqMenuKb('admin'));
        return;
      }
      send($chat, "Ключ не подошёл.");
      return;
    }
    send($chat, "Это бот записи на консультации.\n\n" .
      "Если вы клиентка — откройте ссылку из подтверждения записи, она выглядит так: t.me/бот?start=bКОД.\n\n" .
      "Если вы специалист или куратор — пришлите сюда одним сообщением свой ключ доступа, его даёт координатор.");
    return;
  }

  /* свободный комментарий в конце опроса */
  $st = tgState($chat);
  if($st && $st['state'] === 'comment' && $st['booking_id']){
    $c = ($text === '-' || $text === '—') ? '' : mb_substr($text, 0, 900);
    q("UPDATE psy_bookings SET comment=? WHERE id=?", [$c, $st['booking_id']]);
    setState($chat, '');
    send($chat, "Спасибо! Отзыв записан — он попадает координатору сразу.");
    if($c !== ''){
      $b = one("SELECT * FROM psy_bookings WHERE id=?", [$st['booking_id']]);
      $e = expert($b['expert_id']);
      adminNotify("💬 Отзыв (" . ($b['csat'] ?: '—') . "/5, NPS " . ($b['nps'] ?? '—') . ")\n" .
                  $b['client_name'] . " → " . ($e['name'] ?? '—') . "\n«" . $c . "»");
    }
    return;
  }

  /* Вопрос координатору: специалист нажал «спросить куратора» и пишет следующим сообщением */
  if($st && $st['state'] === 'ask' && in_array($st['role'], ['expert', 'admin'], true)){
    setState($chat, '');
    $who = $st['expert_id'] ? (expert($st['expert_id'])['name'] ?? 'специалист') : 'куратор';
    adminNotify("❓ Вопрос от специалиста\n" . $who . ":\n«" . mb_substr($text, 0, 900) . "»\n\nОтветьте ей в личке — бот пересылать ответы не умеет.");
    send($chat, "Передала координатору. Он ответит вам в личных сообщениях.");
    logit('faq', 'вопрос куратору от ' . $who . ': ' . mb_substr($text, 0, 120));
    return;
  }

  /* Свободный вопрос специалиста или куратора: ищем ответ в базе, не нашли — зовём человека */
  if($st && in_array($st['role'], ['expert', 'admin'], true)){
    $role = $st['role'];
    if(strpos($text, '/help') === 0 || faqNorm($text) === 'вопрос' || faqNorm($text) === 'помощь'){
      send($chat, faqMenuText($role), faqMenuKb($role));
      return;
    }
    $hits = faqFind($text, $role);
    if($hits && $hits[0]['score'] >= 3){
      $rows = [];
      /* если рядом есть второй близкий ответ — предложим и его */
      if(isset($hits[1]) && $hits[1]['score'] >= 3 && $hits[1]['score'] >= $hits[0]['score'] - 1)
        $rows[] = kbRow(btn('Или про: ' . $hits[1]['title'], 'faq:' . $hits[1]['id']));
      $rows[] = $role === 'admin'
        ? kbRow(btn('Все вопросы', 'faq:menu'))
        : kbRow(btn('Не то — спросить куратора', 'faq:ask'), btn('Все вопросы', 'faq:menu'));
      send($chat, $hits[0]['text'], ikb($rows));
      logit('faq', 'ответ «' . $hits[0]['id'] . '» на: ' . mb_substr($text, 0, 100));
      return;
    }
    if($hits){
      $rows = [];
      foreach(array_slice($hits, 0, 3) as $h) $rows[] = kbRow(btn($h['title'], 'faq:' . $h['id']));
      if($role !== 'admin') $rows[] = kbRow(btn('Ничего из этого — спросить куратора', 'faq:ask'));
      send($chat, "Кажется, вопрос про что-то из этого — выберите, что ближе:", ikb($rows));
      return;
    }
    if($role === 'admin'){
      /* куратору звать некого: честно говорим, что не знаем, и показываем, что умеем */
      send($chat, "Такого ответа у меня нет. Вот о чём я могу рассказать:", faqMenuKb('admin'));
    }else{
      adminNotify("❓ Вопрос от специалиста\n" . (expert($st['expert_id'])['name'] ?? 'специалист') . ":\n«" . mb_substr($text, 0, 900) . "»\n\nБот такого ответа не знает. Ответьте ей в личке.");
      send($chat, "Такого ответа я пока не знаю — передала координатору, он напишет вам в личные сообщения.\n\n" .
        "А пока можно посмотреть, что я умею объяснять сама:", faqMenuKb());
    }
    logit('faq', 'без ответа (' . $role . '): ' . mb_substr($text, 0, 120));
    return;
  }
  send($chat, "Я бот-напоминалка. Все действия — на странице записи; здесь только подтверждения, напоминания и отзыв после встречи.");
}

/* ── Итог дня для кураторов ──────────────────────────────────
   Одно сообщение вечером: что случилось за день и что требует внимания завтра. */
function dailyDigest($day = null){
  $day  = $day ?: date('Y-m-d');
  $from = $day . ' 00:00:00';
  $to   = $day . ' 23:59:59';
  $L = [];

  $new = all("SELECT b.*, e.name ename FROM psy_bookings b LEFT JOIN psy_experts e ON e.id=b.expert_id
               WHERE b.created_at BETWEEN ? AND ? ORDER BY b.dt", [$from, $to]);
  $held = all("SELECT b.*, e.name ename FROM psy_bookings b LEFT JOIN psy_experts e ON e.id=b.expert_id
                WHERE b.status='held' AND b.rep_at BETWEEN ? AND ?", [$from, $to]);
  $noshow = (int)one("SELECT COUNT(*) c FROM psy_bookings WHERE status='noshow' AND rep_at BETWEEN ? AND ?", [$from, $to])['c'];
  $cancel = (int)one("SELECT COUNT(*) c FROM psy_history WHERE kind='cancel' AND at BETWEEN ? AND ?", [$from, $to])['c'];
  $moved  = (int)one("SELECT COUNT(*) c FROM psy_history WHERE kind IN ('move','expert','assign') AND at BETWEEN ? AND ?", [$from, $to])['c'];
  $newExp = all("SELECT name FROM psy_experts WHERE created_at BETWEEN ? AND ? ORDER BY name", [$from, $to]);
  $openedRows = all("SELECT e.name, COUNT(*) c FROM psy_slots s JOIN psy_experts e ON e.id=s.expert_id
                      WHERE s.created_at BETWEEN ? AND ? GROUP BY e.id ORDER BY c DESC", [$from, $to]);
  $rated = all("SELECT csat, client_name, comment FROM psy_bookings WHERE csat_at BETWEEN ? AND ?", [$from, $to]);

  $L[] = "📊 Итоги дня, " . (int)date('j', ts($day . ' 12:00:00')) . ' ' .
         [1=>'января',2=>'февраля',3=>'марта',4=>'апреля',5=>'мая',6=>'июня',7=>'июля',8=>'августа',
          9=>'сентября',10=>'октября',11=>'ноября',12=>'декабря'][(int)date('n', ts($day . ' 12:00:00'))];
  $L[] = '';

  $L[] = "Новых записей: " . count($new);
  foreach(array_slice($new, 0, 6) as $b)
    $L[] = "  • " . $b['client_name'] . " → " . ($b['ename'] ?: 'без специалиста') . ", " . fmtRu($b['dt']) .
           (!empty($b['pair']) ? ' (пара)' : '');
  if(count($new) > 6) $L[] = "  …и ещё " . (count($new) - 6);

  $L[] = '';
  $L[] = "Проведено встреч: " . count($held) . ($noshow ? ", неявок: " . $noshow : '');
  foreach($held as $b) $L[] = "  • " . $b['ename'] . " — " . $b['client_name'];
  if($cancel || $moved) $L[] = "Отмен: " . $cancel . ", переносов и замен: " . $moved;

  if($rated){
    $avg = 0; foreach($rated as $r) $avg += (int)$r['csat'];
    $avg = round($avg / count($rated), 1);
    $L[] = '';
    $L[] = "⭐️ Оценок: " . count($rated) . ", средняя " . $avg;
    foreach($rated as $r) if((int)$r['csat'] <= 3)
      $L[] = "  ⚠️ " . $r['client_name'] . " — " . $r['csat'] . "/5" . ($r['comment'] ? ": «" . mb_substr($r['comment'], 0, 80) . "»" : '');
  }

  if($newExp || $openedRows){
    $L[] = '';
    if($newExp){
      $names = [];
      foreach($newExp as $e) $names[] = $e['name'];
      $L[] = "Новых специалистов: " . count($newExp) . " — " . implode(', ', array_slice($names, 0, 5));
    }
    foreach($openedRows as $o) $L[] = "  📅 " . $o['name'] . " открыла слотов: " . $o['c'];
  }

  /* что требует внимания */
  $warn = [];
  foreach(all("SELECT * FROM psy_experts WHERE active=1") as $e){
    $load = loadOf($e['id']); $q = quotaOf($e);
    if($load >= $q) $warn[] = "  • " . $e['name'] . " выбрала квоту (" . $load . " из " . $q . ") — её время скрыто";
    $debt = reportDebt($e['id']);
    if($debt) $warn[] = "  • " . $e['name'] . ": не сдан отчёт по " . $debt . " встрече(ам)";
  }
  $noSpec = all("SELECT client_name, dt FROM psy_bookings WHERE status='searching'");
  foreach($noSpec as $b) $warn[] = "  • " . $b['client_name'] . ", " . fmtRu($b['dt']) . " — без специалиста";
  $tomorrow = all("SELECT client_name, confirm FROM psy_bookings WHERE status='booked' AND dt BETWEEN ? AND ?",
                  [date('Y-m-d 00:00:00', ts($day) + 86400), date('Y-m-d 23:59:59', ts($day) + 86400)]);
  if($tomorrow){
    $unconf = 0;
    foreach($tomorrow as $t) if($t['confirm'] !== 'yes') $unconf++;
    $L[] = '';
    $L[] = "Завтра встреч: " . count($tomorrow) . ($unconf ? ", не подтверждено: " . $unconf : ", все подтверждены");
  }
  $freeTimes = count(openTimes());
  if($freeTimes === 0) $warn[] = "  • свободных слотов не осталось — записываться некуда";

  if($warn){
    $L[] = '';
    $L[] = "❗️ Требует внимания:";
    foreach(array_slice($warn, 0, 10) as $w) $L[] = $w;
  }

  $L[] = '';
  $L[] = "Всего свободных слотов: " . $freeTimes;
  return implode("\n", $L);
}

function sendDigest($day = null){
  $text = dailyDigest($day);
  $sent = 0;
  foreach(all("SELECT chat FROM psy_tg WHERE role='admin'") as $r){ send($r['chat'], $text); $sent++; }
  if(!$sent) sendMail(cfg('alert_email'), 'Итоги дня — запись на консультации', $text);
  return $sent;
}

/* ── Планировщик: напоминания и опросы ──────────────────────── */
function tick(){
  initTables();
  settings();
  $dur  = (int)cfg('duration');
  $nowT = time();
  $rows = all("SELECT * FROM psy_bookings WHERE status IN ('booked','held','noshow','searching')
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
      $txt = "⏰ Завтра встреча\n" . fmtRu($b['dt']) . "\nСпециалист: " . $e['name'] .
        "\nСсылка: " . ($b['link'] ?: 'пришлём отдельно') .
        "\n\nЕсли не сможете — перенесите или отмените, чтобы место досталось другой: " . siteUrl() . "?code=" . $b['code'];
      $via = '';
      if($b['tg_chat'] && tgAlive()){ send($b['tg_chat'], $txt, clientKb($b)); $via = 'tg'; }
      elseif(smsOn() && smsSend(bookingPhone($b), 'Завтра в ' . date('H:i', $t) . tzShort() .
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
      if($b['tg_chat'] && tgAlive()){
        /* Заранее говорим, что делать, если специалист не подключится — иначе клиент просто уйдёт */
        send($b['tg_chat'], "⏰ Через 15 минут встреча\nСсылка: " . ($b['link'] ?: codeUrl($b)) .
          "\n\nЕсли специалиста не будет на месте — подождите 10 минут и перенесите встречу кнопкой ниже, " .
          "место останется за вами.", clientKb($b));
        $via = 'tg';
      }
      elseif(smsOn() && smsSend(bookingPhone($b), $short)) $via = 'sms';
      elseif($b['email'] && sendMail($b['email'], 'Встреча через 15 минут', $short)) $via = 'mail';
      elseif($b['tg_chat']){ send($b['tg_chat'], $short); $via = 'tg'; }
      markSent($b['id'], 'r15', $via ?: 'none');
    }

    /* накануне — эксперту: связаться с клиентом и подтвердить приход */
    if($b['status'] === 'booked' && ($e['tg_chat'] || !empty($e['email'])) && $b['confirm'] === ''
       && $nowT >= $t - 20*3600 && $nowT < $t - 2*3600 && !wasSent($b['id'], 'econf')){
      $txt = "📞 Свяжитесь с клиентом\n" . fmtRu($b['dt']) . (!empty($b['pair']) ? " · ПАРНАЯ" : "") .
        "\n\nКлиент: " . $b['client_name'] .
        (!empty($b['phone'])   ? "\nТелефон: " . $b['phone'] : '') .
        (!empty($b['tg_nick']) ? "\nTelegram: @" . $b['tg_nick'] : '') .
        "\nТема: " . $b['category'] . ($b['note'] ? "\n«" . $b['note'] . "»" : '') .
        "\n\nНапишите или позвоните — короткий разговор накануне почти всегда решает, придёт человек или нет. " .
        "А если не сможет, время успеет достаться другой.";
      if($e['tg_chat']) send($e['tg_chat'], $txt, ikb([
        kbRow(btn('Клиент подтвердил', 'conf:' . $b['id'] . ':yes')),
        kbRow(btn('Не отвечает', 'conf:' . $b['id'] . ':no')),
        kbRow(btn('Отменить встречу', 'ecan:' . $b['id'])),
      ]));
      else sendMail($e['email'], 'Свяжитесь с клиентом — ' . fmtRu($b['dt']), $txt);
      markSent($b['id'], 'econf', $e['tg_chat'] ? 'tg' : 'mail');
    }

    /* за два часа — эксперту */
    if($b['status'] === 'booked' && ($e['tg_chat'] || !empty($e['email']))
       && $nowT >= $t - 2*3600 && $nowT < $t && !wasSent($b['id'], 'e2')){
      $txt = "⏰ Через 2 часа встреча" . (!empty($b['pair']) ? " · ПАРНАЯ" : "") . "\n" . fmtRu($b['dt']) . "\nКлиент: " . $b['client_name'] .
        "\nТема: " . $b['category'] . ($b['note'] ? "\n«" . $b['note'] . "»" : '') .
        "\nСвязь: " . $b['contact'] . "\nСсылка: " . ($b['link'] ?: '—');
      markSent($b['id'], 'e2', tell($e['tg_chat'], $e['email'] ?? '', 'Через 2 часа встреча', $txt, true) ?: 'none');
    }
    /* после встречи — эксперту вопрос об исходе */
    if($b['status'] === 'booked' && $e['tg_chat'] && $nowT >= $end + 5*60 && !wasSent($b['id'], 'eask')){
      send($e['tg_chat'], "Встреча " . fmtRu($b['dt']) . " с " . $b['client_name'] . " — как прошла?", ikb([
        kbRow(btn('Провели', 'held:' . $b['id'] . ':h')),
        kbRow(btn('Клиент не пришёл', 'held:' . $b['id'] . ':n'), btn('Отменили', 'held:' . $b['id'] . ':c')),
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
        "\nВстреча меньше чем через сутки, а специалист не назначен. Клиентка ничего не знает и придёт.");
      markSent($b['id'], 'nospec', 'admin');
    }

    /* отчёт не сдан через 3 часа */
    if($b['status'] === 'held' && !$b['rep_at'] && $e['tg_chat']
       && $nowT >= $end + 3*3600 && !wasSent($b['id'], 'rep')){
      send($e['tg_chat'], "📝 Отчёт по встрече " . fmtRu($b['dt']) . " (" . $b['client_name'] . ") ещё не сдан.\n" .
        "Две минуты в кабинете — и клиент остаётся в системе.");
      markSent($b['id'], 'rep');
      adminNotify("📝 Нет отчёта: " . $e['name'] . " — " . $b['client_name'] . ", " . fmtRu($b['dt']));
    }
  }
  /* вечерняя сводка — один раз в день, после назначенного часа */
  if(cfg('digest_on') === '1'){
    $today = date('Y-m-d');
    $at    = cfg('digest_at') ?: '21:00';
    if(cfg('digest_day') !== $today && date('H:i') >= $at){
      setSetting('digest_day', $today);
      try{ sendDigest($today); }catch(Throwable $e){ logit('digest', $e->getMessage()); }
    }
  }
  /* копия базы раз в сутки */
  /* Днём считаем закрытым только удавшуюся копию: раньше отметка ставилась заранее,
     и один ночной сбой оставлял сутки вообще без бэкапа. Повтор — не чаще раза в полчаса. */
  if(cfg('dump_day') !== date('Y-m-d') && time() - (int)strtotime((string)cfg('dump_try')) > 1800){
    setSetting('dump_try', now());
    try{
      if(file_exists(__DIR__ . '/dbdump.php')){
        require_once __DIR__ . '/dbdump.php';
        list($dumpOk, ) = psyDump();
        if($dumpOk) setSetting('dump_day', date('Y-m-d'));
      }
    }catch(Throwable $e){ logit('dump', $e->getMessage()); }
  }
  /* Молчащий бэкап опаснее шумного: если копии нет больше полутора суток — пишем на почту */
  $lastDump = (int)strtotime((string)cfg('dump_at'));
  if($lastDump && time() - $lastDump > 36*3600 && cfg('dump_alert_day') !== date('Y-m-d')){
    setSetting('dump_alert_day', date('Y-m-d'));
    $to = trim((string)cfg('alert_email'));
    if($to !== '') sendMail($to, 'Агрегатор: нет свежей копии базы',
      "Последняя копия базы сделана " . cfg('dump_at') . ".\n" .
      "С тех пор прошло больше полутора суток — значит, ночная копия не получается.\n" .
      "Причина записана в служебный журнал (раздел координатора, «Настройки» с ?tech=1).");
    logit('dump', 'тревога: свежей копии базы нет с ' . cfg('dump_at'));
  }
  /* Прошедшие никем не занятые часы держать незачем: они только копятся
     и путают счётчики. Занятые слоты не трогаем — на них держится история встреч. */
  if(cfg('slots_clean_day') !== date('Y-m-d')){
    setSetting('slots_clean_day', date('Y-m-d'));
    $gone = q("DELETE FROM psy_slots WHERE booking_id IS NULL AND dt < ?",
              [date('Y-m-d H:i:s', time() - 2*86400)])->rowCount();
    if($gone) logit('slots', "убрано прошедших пустых слотов: $gone");
  }
  hookRetry();
  checkQueueStall();
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
     . "X-Mailer: psy-zapis\r\n";
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
  /* Телефон живёт в своём поле — его и берём. Ни Telegram, ни почта на SMS не влияют. */
  $p = phoneOf($b['phone'] ?? '');
  if($p !== '') return $p;
  /* У старых записей отдельного поля не было: там контакт лежит одной строкой
     вида «+7 900 123-45-67, @nick». Убираем куски с «собакой» — иначе цифры ника
     склеиваются с номером, — и разбираем то, что осталось. */
  $rest = preg_replace('/@\S+/u', ' ', (string)($b['contact'] ?? ''));
  $p = phoneOf($rest);
  if($p !== '') return $p;
  foreach(preg_split('/[,;]+/u', $rest) as $part){
    $p = phoneOf($part);
    if($p !== '') return $p;
  }
  return '';
}
/* Что вписали в поле Telegram, то и храним: у многих он привязан к номеру,
   и телефон там — нормальная запись, а не ошибка. На SMS это не влияет никак. */
function tgNickOf($s){
  return mb_substr(ltrim(trim((string)$s), '@'), 0, 60);
}
function smsSend($contact, $text){
  $GLOBALS['PSY_SMS_ERR'] = '';
  if(!smsOn()) { $GLOBALS['PSY_SMS_ERR'] = 'SMS выключены в настройках'; return false; }
  $phone = phoneOf($contact);
  if($phone === '') { $GLOBALS['PSY_SMS_ERR'] = 'в контакте нет телефона'; return false; }
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
    $GLOBALS['PSY_SMS_ERR'] = $e->getMessage();   /* чтобы причину можно было показать и записать */
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
         "Специалист: " . ($e['name'] ?? '—') . "\n" .
         "Ссылка: " . ($b['link'] ?: 'пришлём отдельно') . "\n" .
         "Код записи: " . $b['code'];
}
function checkQueueStall(){
  if(cfg('tg_token') === '') return;                     /* бот и не включали */
  $old = one("SELECT MIN(created_at) m, COUNT(*) c FROM psy_outbox WHERE status='pending'");
  if(!$old || !$old['c'] || !$old['m']) return;
  if(ts($old['m']) > time() - 3600) return;              /* ждём меньше часа — нормально */
  $last = cfg('last_alert');
  if($last && ts($last) > time() - 3*3600) return;       /* не чаще раза в три часа */
  setSetting('last_alert', now());
  $site = cfg('site_url') ?: (defined('SITE_URL') ? SITE_URL : 'https://booking.example.com') . '/zapis/';
  alertMail(
    'Уведомления не уходят: реле не на связи',
    "В очереди застряло сообщений: " . (int)$old['c'] . ", самое старое с " . $old['m'] . ".\n" .
    "Реле последний раз выходило на связь: " . (cfg('last_relay') ?: 'ни разу') . ".\n\n" .
    "Это значит, что клиентки и специалисты сейчас не получают напоминания.\n" .
    "Что сделать: включить компьютер с реле (папка zapis-relay, файл vkluchit-avtozapusk.command).\n" .
    "Пока реле не поднято — разошлите напоминания вручную: $site , вкладка «Ручная рассылка».\n\n" .
    "Как только реле поднимется, очередь уйдёт сама, ничего не потеряется."
  );
  logit('alert', 'очередь стоит: ' . (int)$old['c']);
}
/* Дёргаем планировщик попутно, не чаще раза в минуту — работает и без крона */
function tickMaybe(){
  $last = one("SELECT v FROM psy_settings WHERE k='last_tick'");
  if($last && ts($last['v']) > time() - 60) return;
  try{ tick(); }catch(Throwable $e){ logit('tick', $e->getMessage()); }
}
