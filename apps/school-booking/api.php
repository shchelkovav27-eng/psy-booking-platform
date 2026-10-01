<?php
/* API агрегатора записи. Все ответы — JSON. */
require_once __DIR__ . '/lib.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

initTables();
settings();

$action = $_GET['action'] ?? '';
$raw    = file_get_contents('php://input');
$in     = $raw ? json_decode($raw, true) : [];
if(!is_array($in)) $in = [];
function inp($k, $d = ''){ global $in; $v = $in[$k] ?? ($_POST[$k] ?? ($_GET[$k] ?? $d)); return is_string($v) ? trim($v) : $v; }

/* Планировщик едет попутно с любым запросом — на случай, если крон ещё не заведён */
if($action !== 'relay') tickMaybe();

try{

/* ═══════════ ОБЩЕЕ ═══════════ */
if($action === 'boot'){
  $self = (isset($_SERVER['HTTPS']) ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? '') .
          preg_replace('~api\.php.*$~', '', (string)($_SERVER['REQUEST_URI'] ?? ''));
  if($self !== cfg('site_url') && strlen($self) > 12) setSetting('site_url', $self);
  ok([
    'times'      => openTimes((int)inp('spec')),
    'speci'      => specialistyDlyaVybora(''),
    'vyborSpeca' => cfg('choose_expert') !== '0',
    'duration'   => (int)cfg('duration'),
    'org'        => cfg('org'),
    'directions' => DIRECTIONS(),
    'topics'     => TOPICS(),
    'roles'      => ROLES(),
    'courses'    => limitOn() ? array_keys(COURSES()) : [],
    'emailReq'   => cfg('email_req') === '1',
    'askTopic'   => cfg('ask_topic') !== '0',
    'askPair'    => cfg('ask_pair')  !== '0',
    'bot'        => cfg('bot_user'),
    'tz'         => cfg('tz'),
    'now'        => now(),
  ]);
}

/* ═══════════ КЛИЕНТ ═══════════ */
if($action === 'book'){
  $when    = inp('dt');
  /* Имя и фамилия раздельно: школа сверяет ученицу со своими списками,
     а «как к вам обращаться» для этого не годится. */
  /* Своя ученица уже всё про себя рассказала в профиле — не спрашиваем
     заново, берём оттуда. Что пришло с формой, только уточняет. */
  $meU = auth();
  $meC = null;
  if($meU && !empty($meU['user_id'])){
    $uu = userById($meU['user_id']);
    if($uu && $uu['role'] === 'client') $meC = clientForUser($uu);
  }
  $first   = mb_substr(trim((string)inp('first')), 0, 60);
  $last    = mb_substr(trim((string)inp('last')), 0, 60);
  $name    = trim($first . ' ' . $last);
  if($name === '') $name = mb_substr(inp('name'), 0, 100);
  if($name === '' && $meC) $name = (string)$meC['name'];
  /* Телеграм и телефон — разные каналы, храним отдельно. */
  $tgNick  = ltrim(mb_substr(trim((string)inp('tg')), 0, 60), '@');
  $phone   = mb_substr(trim((string)inp('phone')), 0, 30);
  $contact = mb_substr(inp('contact'), 0, 100);
  if($meC){
    if($tgNick === '') $tgNick = (string)$meC['tg'];
    if($phone  === '') $phone  = (string)$meC['phone'];
  }
  if($contact === '') $contact = $tgNick !== '' ? '@' . $tgNick : $phone;
  if($contact === '' && $meC) $contact = (string)$meC['contact'];
  $cat     = mb_substr(inp('category'), 0, 100);
  $note    = mb_substr(inp('note'), 0, 300);
  if(!preg_match('/^\d{4}-\d{2}-\d{2}[ T]\d{2}:\d{2}(:\d{2})?$/', $when)) fail('Не разобрали время. Выберите слот заново.');
  $when = str_replace('T', ' ', $when);
  if(strlen($when) === 16) $when .= ':00';
  if(mb_strlen($first) < 2 && mb_strlen($name) < 2) fail('Напишите имя.');
  if(mb_strlen($contact) < 4) fail('Оставьте Telegram или телефон — по ним с вами свяжутся.');
  if($phone !== '' && phoneOf($phone) === '') fail('Проверьте телефон — похоже, в номере опечатка.');
  if(ts($when) < time())      fail('Это время уже прошло. Выберите другое.');

  $dir   = mb_substr(inp('direction'), 0, 60);
  $email = mb_substr(inp('email'), 0, 110);
  if($email === '' && $meC) $email = (string)$meC['email'];
  if(cfg('email_req') === '1' && $email === '') fail('Оставьте почту — по ней школа находит вас в своих записях.');
  if($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) fail('Проверьте адрес почты — похоже, там опечатка.');
  $utm = [];
  foreach(['utm_source','utm_medium','utm_campaign','utm_term'] as $u) $utm[$u] = mb_substr((string)inp($u), 0, 110);
  $pair = (inp('pair') === '1' || inp('pair') === true);
  $course = mb_substr(inp('course'), 0, 110);
  if($course === '' && $meC){ $cs = coursesOf($meC); $course = $cs[0] ?? ''; }
  if(limitOn() && COURSES() && $course === '')
    fail($meC ? 'Отметьте в профиле, на каком вы курсе — от него зависит, сколько консультаций доступно.'
              : 'Выберите курс — от него зависит, сколько консультаций вам доступно.');
  /* Ученица могла выбрать конкретного специалиста — тогда ведём к ней.
     Проверяем, что он вправду свободен в это время: иначе тихо подменим. */
  $hochet = (int)inp('spec');
  if($hochet){
    $ex = expert($hochet);
    $svoboden = one("SELECT id FROM shk_slots WHERE expert_id=? AND dt=? AND booking_id IS NULL", [$hochet, $when]);
    if(!$ex || (int)$ex['active'] !== 1) $hochet = 0;
    elseif(!$svoboden) fail('Это время у выбранного специалиста только что заняли. Выберите другое или другого специалиста.');
  }
  $b = makeBooking($when, $name, $contact, $cat, $note, null, false, $dir, $email, $utm, $pair, $phone, $tgNick, $course,
                   $meC ? (string)$meC['ckey'] : '', $hochet);
  if($b === 'blocked'){
    logRefusal('blocked', $when, $name, $contact, $course);
    fail('Записаться сейчас нельзя. Напишите куратору — он подскажет, что делать.');
  }
  if(is_string($b) && strpos($b, 'soon:') === 0){
    $prev = substr($b, 5);
    $d = cooldownDays();
    logRefusal('cooldown', $when, $name, $contact, $course, 'прошлая ' . $prev);
    /* Главное для ученицы — не «сколько дней правило», а с какого числа
       ей уже можно. Считаем дату сами, чтобы она не считала в уме. */
    $mozhno = date('Y-m-d H:i:s', ts($prev) + $d * 86400);
    $bylo   = ts($prev) < time();
    fail(($bylo ? 'Вы уже были на встрече ' : 'У вас уже назначена встреча ') . fmtRu($prev) . '. ' .
         'Записаться снова можно с ' . fmtData($mozhno) . ' — между встречами должно пройти ' . dney($d) . '. ' .
         'Если нужно раньше, напишите куратору: он запишет вас вручную.');
  }
  if($b === 'limit'){
    /* У залогиненной ученицы ключ уже известен. Считать его заново из
       контакта нельзя: выйдет другой ключ, и в сообщении будут цифры
       чужой (несуществующей) карточки — «0 из 1» вместо настоящих. */
    $ck = $meC ? (string)$meC['ckey'] : clientKey($contact, $email, $name);
    $cl = clientFind($ck);
    logRefusal('limit', $when, $name, $contact, $course,
               'использовано ' . clientUsed($ck) . ' из ' . clientAllowed($cl));
    fail('Открытые вам встречи пока использованы: ' . clientUsed($ck) . ' из ' . clientAllowed($cl) .
         '. Не переживайте — тарифы у всех разные, и куратор в ближайшее время откроет вам ' .
         'запланированное количество. Если встреча нужна срочно, напишите куратору.');
  }
  if(!$b){
    logRefusal('taken', $when, $name, $contact, $course);
    fail($pair
      ? 'На это время специалист для парной консультации уже занят. Выберите другое время.'
      : 'Это время только что заняли. Выберите другое, пожалуйста.');
  }
  $e = expert($b['expert_id']);
  ok(['booking' => [
    'pending'=> $b['status'] === 'pending',
    'code'   => $b['code'],
    'dt'     => $b['dt'],
    'expert' => $e['name'],
    'link'   => $b['link'],
    'tg'     => botLink('b' . $b['code']),
  ]]);
}

if($action === 'booking'){
  $code = strtoupper(inp('code'));
  $b = one("SELECT * FROM shk_bookings WHERE code=?", [$code]);
  if(!$b) fail('Запись не найдена. Проверьте код — пять символов из подтверждения.', 404);
  $e = expert($b['expert_id']);
  ok(['booking' => [
    'code'     => $b['code'],
    'dt'       => $b['dt'],
    'expert'   => $e['name'] ?? '—',
    'link'     => $b['link'],
    'status'   => ($b['status'] === 'searching' ? 'booked' : $b['status']),
    'csat'     => $b['csat'],
    'duration' => (int)cfg('duration'),
    'tg'       => botLink('b' . $b['code']),
  ]]);
}

if($action === 'feedback'){
  $b = one("SELECT * FROM shk_bookings WHERE code=?", [strtoupper(inp('code'))]);
  if(!$b) fail('Запись не найдена.', 404);
  $score = (int)inp('score');
  if($score < 1 || $score > 5) fail('Поставьте оценку от 1 до 5.');
  $nps = (int)inp('nps'); if($nps < 0) $nps = 0; if($nps > 10) $nps = 10;
  q("UPDATE shk_bookings SET csat=?, fit=?, nps=?, comment=?, csat_at=? WHERE id=?",
    [$score, mb_substr(inp('fit'), 0, 20), $nps, mb_substr(inp('comment'), 0, 900), now(), $b['id']]);
  $e = expert($b['expert_id']);
  if($score <= 3) adminNotify("⚠️ Низкая оценка $score/5 (с сайта)\n" . $b['client_name'] . " → " . ($e['name'] ?? '—') . ", " . fmtRu($b['dt']));
  ok();
}

if($action === 'reschedule'){
  $b = one("SELECT * FROM shk_bookings WHERE code=?", [strtoupper(inp('code'))]);
  if(!$b) fail('Запись не найдена.', 404);
  if($b['status'] === 'cancelled') fail('Эта запись отменена — выберите время заново на главной.');
  if(ts($b['dt']) < time()) fail('Встреча уже прошла — переносить нечего.');
  $when = str_replace('T', ' ', inp('dt'));
  if(!preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}(:\d{2})?$/', $when)) fail('Не разобрали новое время.');
  if(strlen($when) === 16) $when .= ':00';
  if(ts($when) < time()) fail('Это время уже прошло. Выберите другое.');
  if($when === $b['dt'])  fail('Это то же самое время.');

  $nb = rescheduleBooking($b, $when, 'клиент');
  if(!$nb) fail('Это время только что заняли. Выберите другое — старое место пока за вами.');
  $e = expert($nb['expert_id']);
  ok(['booking' => [
    'code' => $nb['code'], 'dt' => $nb['dt'], 'expert' => $e['name'] ?? '—',
    'link' => $nb['link'], 'status' => $nb['status'], 'csat' => $nb['csat'],
    'duration' => (int)cfg('duration'), 'tg' => botLink('b' . $nb['code']),
  ]]);
}

if($action === 'cancel_by_code'){
  $b = one("SELECT * FROM shk_bookings WHERE code=?", [strtoupper(inp('code'))]);
  if(!$b) fail('Запись не найдена.', 404);
  if(ts($b['dt']) < time()) fail('Встреча уже прошла — отменять нечего.');
  cancelBooking($b, 'клиент');
  ok();
}

/* ═══════════ ВХОД ═══════════ */
if($action === 'wait_add'){
  $name    = mb_substr(inp('name'), 0, 100);
  $contact = mb_substr(inp('contact'), 0, 100);
  $email   = mb_substr(inp('email'), 0, 110);
  $topic   = mb_substr(inp('topic'), 0, 100);
  $note    = mb_substr(inp('note'), 0, 300);
  $pair    = (inp('pair') === '1' || inp('pair') === true) ? 1 : 0;
  if(mb_strlen($name) < 2)    fail('Напишите, как к вам обращаться.');
  if(mb_strlen($contact) < 4) fail('Оставьте Telegram или телефон — иначе мы не сможем позвать.');
  if($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) fail('Проверьте адрес почты — похоже, там опечатка.');
  /* повторная заявка не плодит строки: обновляем ту же */
  $old = one("SELECT * FROM shk_wait WHERE status='waiting' AND contact=?", [$contact]);
  if($old){
    q("UPDATE shk_wait SET name=?, email=?, topic=?, note=?, pair=? WHERE id=?",
      [$name, $email, $topic, $note, $pair, $old['id']]);
    $code = $old['code'];
  }else{
    $code = waitCode();
    q("INSERT INTO shk_wait(code,name,contact,email,topic,note,pair,status,created_at)
       VALUES(?,?,?,?,?,?,?, 'waiting', ?)", [$code, $name, $contact, $email, $topic, $note, $pair, now()]);
    adminNotify("⏳ В очереди: " . $name . ($topic !== '' ? " — " . $topic : ''));
    logRefusal('no_time', null, $name, $contact, '', $topic);
  }
  ok(['code' => $code, 'tg' => botLink('w' . $code)]);
}

/* Сводка отказов: сколько раз и почему не смогли записаться. */
/* «Мои встречи»: просим почту и шлём ссылку. Ответ одинаковый в любом
   случае — иначе по форме можно было бы проверять, кто у нас записан. */
if($action === 'my_request'){
  $em = mb_strtolower(trim((string)inp('email')));
  if($em === '' || !filter_var($em, FILTER_VALIDATE_EMAIL)) fail('Проверьте адрес почты.');
  $t = accessMake($em);
  if($t !== ''){
    sendMail($em, 'Ваши встречи с психологом',
      "Здравствуйте!\n\nПо этой ссылке видно все ваши встречи, сколько консультаций осталось " .
      "и можно перенести или отменить запись:\n\n" . siteUrl() . "?me=" . $t . "\n\n" .
      "Ссылка личная — не пересылайте её. Работает месяц.\n" .
      "Если это были не вы, просто удалите письмо: ничего не произошло.");
  }
  ok(['sent' => true]);
}

/* ── Профиль ученицы ── */
function meClient(){
  $a = auth();
  if(!$a || empty($a['user_id'])) fail('Нужен вход.', 401);
  $u = userById($a['user_id']);
  if(!$u || $u['role'] !== 'client') fail('Это раздел ученицы.', 403);
  return [$u, clientForUser($u)];
}

if($action === 'client_profile'){
  list($u, $c) = meClient();
  ok(['profile' => [
      'name' => $u['name'], 'email' => $u['email'],
      'phone' => $c['phone'] ?? '', 'tg' => $c['tg'] ?? '',
      'courses' => coursesOf($c), 'about' => $c['about'] ?? '',
      'photo' => ($c['photo'] ?? '') !== '' ? 'foto/' . $c['photo'] : '',
      'used' => clientUsed($c['ckey']), 'allowed' => clientAllowed($c),
      'blocked' => (int)($c['blocked'] ?? 0),
      'speci' => specialistyDlyaVybora((string)$c['ckey']),
      /* Личная ссылка на бота: одна на все её записи, работает и до первой */
      'tgLink' => cfg('bot_user') ? botLink('c' . clientTgKey($c)) : '',
      'tgBound' => !empty($c['tg_chat']),
    ],
    'allCourses' => array_keys(COURSES())]);
}

if($action === 'client_profile_save'){
  list($u, $c) = meClient();
  $name  = mb_substr(trim((string)inp('name')), 0, 110);
  $phone = mb_substr(trim((string)inp('phone')), 0, 30);
  $tg    = ltrim(mb_substr(trim((string)inp('tg')), 0, 60), '@');
  $about = mb_substr(trim((string)inp('about')), 0, 380);
  $courses = inp('courses', []);
  if(!is_array($courses)) $courses = array_filter(array_map('trim', explode(',', (string)$courses)));
  $courses = array_slice(array_values(array_unique($courses)), 0, 6);
  if(mb_strlen($name) < 2) fail('Впишите имя и фамилию.');
  if($phone !== '' && phoneOf($phone) === '') fail('Проверьте телефон — похоже, в номере опечатка.');

  $contact = $tg !== '' ? '@' . $tg : ($phone !== '' ? $phone : (string)$c['contact']);
  q("UPDATE shk_clients SET name=?, phone=?, tg=?, about=?, courses=?, course=?, contact=? WHERE id=?",
    [$name, $phone, $tg, $about, implode(', ', $courses), $courses[0] ?? (string)$c['course'],
     $contact, $c['id']]);
  q("UPDATE shk_users SET name=? WHERE id=?", [$name, $u['id']]);
  ok();
}

/* Фото по желанию. Кладём рядом с сайтом, но в папку, которую выкладка
   не трогает — иначе следующий деплой стёр бы все загруженные снимки. */
if($action === 'client_photo'){
  list($u, $c) = meClient();
  if(empty($_FILES['photo']['tmp_name'])) fail('Файл не долетел. Попробуйте ещё раз.');
  $f = $_FILES['photo'];
  if($f['size'] > 3*1024*1024) fail('Файл больше трёх мегабайт — выберите поменьше.');
  $info = @getimagesize($f['tmp_name']);
  if(!$info) fail('Это не похоже на картинку.');
  $ext = ['image/jpeg'=>'jpg','image/png'=>'png','image/webp'=>'webp'][$info['mime']] ?? '';
  if($ext === '') fail('Подойдёт JPG, PNG или WEBP.');
  $dir = __DIR__ . '/foto';
  if(!is_dir($dir)) @mkdir($dir, 0755, true);
  $nameF = 'c' . $c['id'] . '_' . substr(bin2hex(random_bytes(6)), 0, 10) . '.' . $ext;
  if(!@move_uploaded_file($f['tmp_name'], $dir . '/' . $nameF)) fail('Не получилось сохранить файл.');
  if(($c['photo'] ?? '') !== '') @unlink($dir . '/' . $c['photo']);
  q("UPDATE shk_clients SET photo=? WHERE id=?", [$nameF, $c['id']]);
  ok(['photo' => 'foto/' . $nameF]);
}

if($action === 'client_photo_del'){
  list($u, $c) = meClient();
  if(($c['photo'] ?? '') !== '') @unlink(__DIR__ . '/foto/' . $c['photo']);
  q("UPDATE shk_clients SET photo='' WHERE id=?", [$c['id']]);
  ok();
}

if($action === 'my_view'){
  /* Два пути внутрь: своя сессия или разовая ссылка из письма. */
  $ckey = '';
  $me = auth();
  if($me && !empty($me['user_id'])){
    $u = userById($me['user_id']);
    if($u && $u['role'] === 'client'){
      $ckey = (string)$u['ckey'];
      /* Карточка могла появиться позже регистрации — досвязываем по почте */
      if($ckey === ''){
        $c0 = one("SELECT ckey FROM shk_clients WHERE LOWER(email)=?", [mb_strtolower($u['email'])]);
        if($c0){ $ckey = $c0['ckey']; q("UPDATE shk_users SET ckey=? WHERE id=?", [$ckey, $u['id']]); }
      }
      if($ckey === '') ok(['name' => $u['name'], 'course' => '', 'used' => 0, 'allowed' => 0,
                           'blocked' => 0, 'bookings' => []]);
    }
  }
  if($ckey === ''){
    $a = accessCheck(inp('t'));
    if(!$a) fail('Нужен вход.', 403);
    $ckey = $a['ckey'];
  }
  $a = ['ckey' => $ckey];
  $c = clientFind($a['ckey']);
  $rows = all("SELECT b.id, b.code, b.dt, b.status, b.link, b.note, b.adm_note, e.name expert
                 FROM shk_bookings b LEFT JOIN shk_experts e ON e.id=b.expert_id
                WHERE b.ckey=? ORDER BY b.dt DESC", [$a['ckey']]);
  ok(['name' => $c ? $c['name'] : '',
      'course' => $c ? $c['course'] : '',
      'used' => clientUsed($a['ckey']),
      'allowed' => $c ? clientAllowed($c) : 0,
      'blocked' => $c ? (int)$c['blocked'] : 0,
      'bookings' => $rows]);
}

/* Поиск по всей истории, включая то, что не попало в окно дашборда. */
if($action === 'bookings_find'){
  requireRole('admin');
  $q = trim((string)inp('q'));
  if(mb_strlen($q) < 2) ok(['rows' => [], 'total' => 0]);
  $like = '%' . str_replace(['%','_'], ['\\%','\\_'], $q) . '%';
  $rows = all("SELECT b.*, e.name ename FROM shk_bookings b LEFT JOIN shk_experts e ON e.id=b.expert_id
                WHERE b.client_name LIKE ? OR b.contact LIKE ? OR b.phone LIKE ? OR b.tg_nick LIKE ?
                   OR b.email LIKE ? OR b.code LIKE ? OR b.course LIKE ? OR e.name LIKE ?
                ORDER BY b.dt DESC LIMIT 200",
              [$like,$like,$like,$like,$like,$like,$like,$like]);
  ok(['rows' => $rows, 'total' => count($rows)]);
}

if($action === 'refusals'){
  requireRole('admin');
  /* Промежуток задаётся датами — тем же, что и период на дашборде,
     иначе сводка считалась бы за свои 30 дней и расходилась с цифрами выше. */
  $d = (string)inp('from');
  $u = (string)inp('to');
  $from = preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) ? $d . ' 00:00:00' : '1970-01-01 00:00:00';
  $to   = preg_match('/^\d{4}-\d{2}-\d{2}$/', $u) ? $u . ' 23:59:59' : '2999-01-01 00:00:00';
  $by = [];
  foreach(all("SELECT why, COUNT(*) c FROM shk_refusals WHERE at>=? AND at<=? GROUP BY why", [$from, $to]) as $r)
    $by[$r['why']] = (int)$r['c'];
  ok(['names' => REFUSALS(), 'by' => $by, 'total' => array_sum($by),
      'rows' => all("SELECT * FROM shk_refusals WHERE at>=? AND at<=? ORDER BY at DESC LIMIT 100", [$from, $to])]);
}

if($action === 'clients'){
  requireRole('admin');
  $rows = all("SELECT * FROM shk_clients ORDER BY name");
  $out = [];
  foreach($rows as $c){
    $used = clientUsed($c['ckey']);
    $out[] = ['id' => (int)$c['id'], 'name' => $c['name'], 'fio' => famFirst($c['name']), 'contact' => $c['contact'],
              'phone' => $c['phone'] ?? '', 'tg' => $c['tg'] ?? '', 'email' => $c['email'] ?? '',
              'course' => $c['course'], 'courses' => implode(', ', coursesOf($c)),
              'allowed' => clientAllowed($c), 'used' => $used, 'at' => $c['created_at'],
              'note' => $c['note'], 'blocked' => (int)$c['blocked'], 'blocked_why' => $c['blocked_why']];
  }
  /* Помечаем возможных двойников: одинаковое имя при разных ключах почти
     всегда значит, что это один человек из разных таблиц. Ссылаемся на id,
     а не на позицию в списке — список ниже сортируется. */
  /* Порядок слов не считаем: «Татьяна Морозова» и «Морозова Татьяна»
     в старых таблицах — один человек, и это самый частый вид задвоения. */
  $ne = [];
  foreach(all("SELECT k1,k2 FROM shk_nodupe") as $r) $ne[$r['k1'] . '|' . $r['k2']] = 1;
  $ck = []; foreach($rows as $r) $ck[(int)$r['id']] = $r['ckey'];
  $byName = [];
  foreach($out as $c) $byName[nameKeySort($c['name'])][] = (int)$c['id'];
  foreach($out as &$c){
    $same = array_values(array_diff($byName[nameKeySort($c['name'])] ?? [], [(int)$c['id']]));
    /* пары, про которые уже решили «разные люди», дублями не считаем */
    $same = array_values(array_filter($same, function($id2) use ($ne, $ck, $c){
      $k = [$ck[(int)$c['id']] ?? '', $ck[$id2] ?? '']; sort($k);
      return !isset($ne[$k[0] . '|' . $k[1]]);
    }));
    $c['dupe'] = $same;
  }
  unset($c);
  /* По алфавиту, фамилия впереди: так список читается как классный журнал */
  usort($out, function($a, $b){ return strcmp(fioKey($a['name']), fioKey($b['name'])); });
  ok(['clients' => $out, 'courses' => COURSES()]);
}

if($action === 'booking_ok'){
  requireRole('admin');
  $b = one("SELECT * FROM shk_bookings WHERE id=?", [(int)inp('id')]);
  if(!$b) fail('Заявка не найдена.', 404);
  $note = mb_substr(inp('note'), 0, 380);
  if($note !== '') q("UPDATE shk_bookings SET adm_note=? WHERE id=?", [$note, $b['id']]);
  /* Ссылка не обязательна. Пустой запас — не повод отказывать ученице:
     время за ней держится, а ссылку допишет психолог или координатор.
     Ученице в письме честно сказано «пришлём отдельно». */
  $manual = trim((string)inp('link'));
  if(!approveBooking($b, 'координатор', $manual)) fail('Эту заявку уже обработали.');
  $b = one("SELECT * FROM shk_bookings WHERE id=?", [$b['id']]);
  ok(['link' => (string)$b['link']]);
}

if($action === 'booking_no'){
  requireRole('admin');
  $b = one("SELECT * FROM shk_bookings WHERE id=?", [(int)inp('id')]);
  if(!$b) fail('Заявка не найдена.', 404);
  if(!rejectBooking($b, mb_substr(inp('why'), 0, 190), 'координатор')) fail('Эту заявку уже обработали.');
  ok();
}

/* Лента по ученице: где была, у кого, чем закончилось. */
/* История клиента для психолога. Ученица может ходить и к одному, и к
   другому — приходить на встречу вслепую, не зная, что уже обсуждали,
   значит начинать с нуля и тратить сессию на пересказ.
   Доступ узкий: только к тем, кто записан именно к этому психологу. */
if($action === 'client_history'){
  $a = requireRole('expert');
  $b = one("SELECT ckey, client_name FROM shk_bookings WHERE id=? AND expert_id=?",
           [(int)inp('id'), $a['expert_id']]);
  if(!$b) fail('Встреча не найдена.', 404);
  if((string)$b['ckey'] === '') ok(['name' => $b['client_name'], 'history' => [], 'client' => null]);
  $c = one("SELECT * FROM shk_clients WHERE ckey=?", [$b['ckey']]);
  $rows = all("SELECT b.dt, b.status, b.rep_req, b.rep_plan, b.adm_note, b.note, e.name expert
                 FROM shk_bookings b LEFT JOIN shk_experts e ON e.id = b.expert_id
                WHERE b.ckey=? AND b.status<>'cancelled' ORDER BY b.dt DESC", [$b['ckey']]);
  ok(['name' => $b['client_name'],
      'client' => $c ? ['course' => $c['course'], 'note' => $c['note'],
                        'used' => clientUsed($b['ckey']), 'allowed' => clientAllowed($c)] : null,
      'history' => $rows]);
}

if($action === 'client_card'){
  requireRole('admin');
  $c = one("SELECT * FROM shk_clients WHERE id=?", [(int)inp('id')]);
  if(!$c) fail('Ученица не найдена.', 404);
  $rows = all("SELECT b.id, b.dt, b.status, b.code, b.note, b.adm_note, b.rep_req, b.rep_plan, e.name expert
                 FROM shk_bookings b LEFT JOIN shk_experts e ON e.id = b.expert_id
                WHERE b.ckey=? ORDER BY b.dt DESC", [$c['ckey']]);
  /* Кандидаты на склейку: то же имя, другой ключ. */
  $dupes = [];
  foreach(all("SELECT * FROM shk_clients WHERE id<>?", [$c['id']]) as $o){
    if(nameKey($o['name']) !== nameKey($c['name'])) continue;
    $dupes[] = ['id' => (int)$o['id'], 'name' => $o['name'], 'contact' => $o['contact'],
                'course' => $o['course'], 'used' => clientUsed($o['ckey'])];
  }
  ok(['dupes' => $dupes, 'client' => [
      'id' => (int)$c['id'], 'name' => $c['name'], 'contact' => $c['contact'], 'email' => $c['email'],
      'phone' => $c['phone'] ?? '', 'tg' => $c['tg'] ?? '',
      'course' => $c['course'], 'allowed' => clientAllowed($c), 'used' => clientUsed($c['ckey']),
      'note' => $c['note'], 'blocked' => (int)$c['blocked'], 'blocked_why' => $c['blocked_why'],
    ], 'history' => $rows]);
}

if($action === 'dupes'){
  requireRole('admin');
  ok(['pairs' => dupePairs()]);
}

if($action === 'dupe_not'){
  requireRole('admin');
  $a = one("SELECT ckey FROM shk_clients WHERE id=?", [(int)inp('aId')]);
  $b = one("SELECT ckey FROM shk_clients WHERE id=?", [(int)inp('bId')]);
  if(!$a || !$b) fail('Карточка не найдена.', 404);
  $k = [$a['ckey'], $b['ckey']]; sort($k);
  q("INSERT IGNORE INTO shk_nodupe(k1,k2,who,at) VALUES(?,?,?,?)", [$k[0], $k[1], 'координатор', now()]);
  logit('merge', 'Отмечено: разные люди — карточки ' . (int)inp('aId') . ' и ' . (int)inp('bId'));
  ok();
}

if($action === 'client_merge'){
  requireRole('admin');
  if(!clientMerge((int)inp('id'), (int)inp('from'))) fail('Не получилось склеить — обновите страницу.');
  ok();
}

if($action === 'client_block'){
  requireRole('admin');
  $id = (int)inp('id');
  $on = inp('on') === '1' ? 1 : 0;
  q("UPDATE shk_clients SET blocked=?, blocked_why=? WHERE id=?",
    [$on, $on ? mb_substr(inp('why'), 0, 190) : '', $id]);
  ok();
}

if($action === 'user_access'){
  requireRole('owner');
  $u = userById((int)inp('id'));
  if(!$u) fail('Аккаунт не найден.', 404);
  $a = auth();
  if((int)$u['id'] === (int)($a['user_id'] ?? 0)) fail('Себе доступ не закрывают.');
  if($u['role'] === 'owner') fail('Владельцу доступ не закрыть.');
  $novy = inp('open') === '1' ? 'active' : 'closed';
  q("UPDATE shk_users SET status=? WHERE id=?", [$novy, $u['id']]);
  /* Живые входы обрываем сразу: иначе закрытый доступ подействует только
     после того, как человек сам выйдет. */
  if($novy === 'closed') q("DELETE FROM shk_tokens WHERE user_id=?", [$u['id']]);
  logit('access', ($novy === 'closed' ? 'Закрыт' : 'Возвращён') . ' доступ: ' . $u['email']);
  ok();
}

if($action === 'client_add'){
  requireRole('admin');
  $name  = mb_substr(trim(inp('name')), 0, 110);
  if($name === '') fail('Впишите фамилию и имя.');
  $phone = phoneOf(inp('phone'));
  $tg    = ltrim(mb_substr(trim(inp('tg')), 0, 60), '@');
  $email = mb_strtolower(trim(inp('email')));
  if($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) fail('Почта написана с ошибкой.');
  if(inp('phone') !== '' && $phone === '') fail('Не разобрали телефон. Формат: +7 999 123-45-67');

  /* Ключ склейки тот же, что и при записи, — иначе заведённая руками
     ученица и её будущая запись станут двумя разными карточками. */
  $contact = $phone !== '' ? $phone : ($tg !== '' ? '@' . $tg : $email);
  $ckey = clientKey($contact, $email, $name);
  if(clientFind($ckey)) fail('Такая ученица уже есть — найдите её поиском.');

  $course  = mb_substr(trim(inp('course')), 0, 110);
  $allowed = inp('allowed', null);
  $allowed = ($allowed === null || $allowed === '') ? courseSessions($course) : max(0, (int)$allowed);
  q("INSERT INTO shk_clients(ckey,name,contact,phone,tg,email,course,allowed,created_at) VALUES(?,?,?,?,?,?,?,?,?)",
    [$ckey, $name, $contact, $phone, $tg, $email, $course, $allowed, now()]);
  logit('client', 'Куратор завёл ученицу: ' . $name);
  ok(['id' => (int)db()->lastInsertId()]);
}

/* Что именно исчезнет вместе с ученицей — спрашиваем до удаления,
   чтобы куратор решал, видя цифры, а не вслепую. */
if($action === 'client_del_info'){
  requireRole('admin');
  $c = one("SELECT * FROM shk_clients WHERE id=?", [(int)inp('id')]);
  if(!$c) fail('Ученица не найдена.', 404);
  $vsego  = (int)one("SELECT COUNT(*) c FROM shk_bookings WHERE ckey=?", [$c['ckey']])['c'];
  $vperedi = (int)one("SELECT COUNT(*) c FROM shk_bookings WHERE ckey=? AND dt>? AND status<>'cancelled'",
                      [$c['ckey'], now()])['c'];
  $akk = one("SELECT id,email FROM shk_users WHERE ckey=? OR (email<>'' AND LOWER(email)=?)",
             [$c['ckey'], mb_strtolower((string)$c['email'])]);
  ok(['name' => famFirst($c['name']), 'vsego' => $vsego, 'vperedi' => $vperedi,
      'akkaunt' => $akk ? $akk['email'] : '', 'blocked' => (int)$c['blocked']]);
}

if($action === 'client_del'){
  requireRole('admin');
  $c = one("SELECT * FROM shk_clients WHERE id=?", [(int)inp('id')]);
  if(!$c) fail('Ученица не найдена.', 404);
  $ck = $c['ckey'];
  $n  = (int)one("SELECT COUNT(*) c FROM shk_bookings WHERE ckey=?", [$ck])['c'];
  /* Карточка с историей уносит её с собой — на это нужно отдельное «да».
     Кнопка присылает подтверждение только после того, как показала цифры. */
  if($n && inp('confirm') !== '1')
    fail('У этой ученицы ' . $n . ' встреч(и). Подтвердите удаление вместе с историей.');

  /* Всё одной транзакцией: если что-то не заладится посреди пути, ученица
     не должна остаться наполовину удалённой — без встреч, но с карточкой. */
  $pdo = db();
  $pdo->beginTransaction();
  try{
    /* Будущие часы возвращаем специалистам: они снова свободны для записи */
    q("UPDATE shk_slots SET booking_id=NULL WHERE booking_id IN (SELECT id FROM shk_bookings WHERE ckey=?)", [$ck]);
    q("DELETE FROM shk_history WHERE booking_id IN (SELECT id FROM shk_bookings WHERE ckey=?)", [$ck]);
    q("DELETE FROM shk_sent    WHERE booking_id IN (SELECT id FROM shk_bookings WHERE ckey=?)", [$ck]);
    q("DELETE FROM shk_bookings WHERE ckey=?", [$ck]);
    /* В очереди ключа ученицы нет — она там по контакту и почте */
    if(trim((string)$c['email']) !== '') q("DELETE FROM shk_wait WHERE LOWER(email)=?", [mb_strtolower($c['email'])]);
    if(trim((string)$c['contact']) !== '') q("DELETE FROM shk_wait WHERE contact=?", [$c['contact']]);
    /* Аккаунт тоже: иначе она войдёт и заведёт себе пустую карточку заново */
    foreach(all("SELECT id FROM shk_users WHERE ckey=? OR (email<>'' AND LOWER(email)=?)",
                [$ck, mb_strtolower((string)$c['email'])]) as $u){
      q("DELETE FROM shk_tokens WHERE user_id=?", [(int)$u['id']]);
      q("DELETE FROM shk_users  WHERE id=?", [(int)$u['id']]);
    }
    q("DELETE FROM shk_nodupe  WHERE k1=? OR k2=?", [$ck, $ck]);
    q("DELETE FROM shk_clients WHERE id=?", [$c['id']]);
    $pdo->commit();
  }catch(Throwable $ex){
    if($pdo->inTransaction()) $pdo->rollBack();
    logit('client', 'Удаление не прошло: ' . $c['name'] . ' — ' . $ex->getMessage());
    fail('Не получилось удалить — ничего не изменилось. ' . $ex->getMessage());
  }

  $kto = auth();
  logit('client', 'Удалена ученица: ' . $c['name'] . ' (встреч: ' . $n . '), ' .
                  'кем: ' . ($kto['role'] ?? '—'));
  ok(['udaleno' => $n]);
}

if($action === 'client_save'){
  requireRole('admin');
  $id = (int)inp('id');
  $c  = one("SELECT * FROM shk_clients WHERE id=?", [$id]);
  if(!$c) fail('Ученица не найдена.', 404);
  $allowed = inp('allowed', null);
  $course  = inp('course', null);
  $note    = inp('note', null);
  if($allowed !== null){
    $bylo  = clientAllowed($c);
    $ispol = clientUsed($c['ckey']);
    q("UPDATE shk_clients SET allowed=? WHERE id=?", [max(0, (int)$allowed), $id]);
    /* Ученица упёрлась в лимит, куратор его поднял — и она об этом не узнает,
       если ей не сказать. Именно так люди и «пропадают»: им отказали, потом
       молча открыли, а они сидят и ждут. Пишем, только если стена убрана. */
    $stalo = clientAllowed(one("SELECT * FROM shk_clients WHERE id=?", [$id]));
    if($bylo <= $ispol && $stalo > $ispol){
      $ost = $stalo - $ispol;
      $txt = "Здравствуйте, " . $c['name'] . "!\n\n" .
             "Куратор открыл вам встречи — можно записываться. Доступно: " . $ost . ".\n" .
             siteUrl();
      $chat = one("SELECT tg_chat FROM shk_clients WHERE id=? AND COALESCE(tg_chat,0)<>0", [$id]);
      if($chat) send($chat['tg_chat'], $txt);
      if(trim((string)$c['email']) !== '') sendMail($c['email'], 'Вам открыли встречи — можно записываться', $txt);
      logit('client', 'Открыли встречи и сообщили ученице: ' . $c['name'] . ' (' . $ispol . ' → ' . $stalo . ')');
    }
  }
  if($course  !== null){
    $course = mb_substr($course, 0, 110);
    q("UPDATE shk_clients SET course=? WHERE id=?", [$course, $id]);
    /* Перенесённые из таблиц встречи курса не знают — их было 334, и без
       этого «По курсам» на дашборде вечно показывал бы «не указан».
       Дописываем курс только туда, где его нет: чужой не перетираем. */
    if($course !== '')
      q("UPDATE shk_bookings SET course=? WHERE ckey=? AND COALESCE(course,'')=''", [$course, $c['ckey']]);
  }
  if($note    !== null) q("UPDATE shk_clients SET note=? WHERE id=?", [mb_substr($note, 0, 380), $id]);
  if(inp('name', null) !== null) q("UPDATE shk_clients SET name=? WHERE id=?", [mb_substr(inp('name'), 0, 110), $id]);
  if(inp('phone', null) !== null) q("UPDATE shk_clients SET phone=? WHERE id=?", [phoneOf(inp('phone')), $id]);
  if(inp('tg', null)    !== null) q("UPDATE shk_clients SET tg=? WHERE id=?", [ltrim(mb_substr(trim(inp('tg')), 0, 60), '@'), $id]);
  if(inp('email', null) !== null){
    $em = mb_strtolower(trim(inp('email')));
    if($em !== '' && !filter_var($em, FILTER_VALIDATE_EMAIL)) fail('Почта написана с ошибкой.');
    q("UPDATE shk_clients SET email=? WHERE id=?", [$em, $id]);
  }
  /* Карточка и кабинет — одно целое. Если у ученицы есть аккаунт с этой
     почтой, пришиваем его к карточке: всё, что вписал куратор, она увидит
     у себя в профиле. Логин (почту входа) при этом не трогаем. */
  $c2 = one("SELECT * FROM shk_clients WHERE id=?", [$id]);
  if($c2 && trim((string)$c2['email']) !== ''){
    $akk = one("SELECT * FROM shk_users WHERE LOWER(email)=? AND role='client'", [mb_strtolower($c2['email'])]);
    if($akk && (string)$akk['ckey'] !== (string)$c2['ckey']){
      q("UPDATE shk_users SET ckey=? WHERE id=?", [$c2['ckey'], $akk['id']]);
      logit('client', 'Аккаунт ' . $akk['email'] . ' пришит к карточке: ' . $c2['name']);
    }
  }
  ok();
}

if($action === 'wait_del'){
  requireRole('admin');
  q("UPDATE shk_wait SET status='done', closed_at=? WHERE id=?", [now(), (int)inp('id')]);
  ok();
}

/* ══════════ ЕДИНЫЙ ВХОД ══════════
   Одна почта, один пароль, роль решает, что человек увидит. */

if($action === 'auth_register'){
  $r = userRegister(inp('email'), inp('pass'), inp('name'), inp('role'), inp('phone'), inp('tg'));
  if(isset($r['err'])) fail($r['err']);
  if($r['status'] === 'pending')
    ok(['pending' => true, 'msg' => 'Заявка отправлена. Владелец подтвердит доступ — мы напишем на почту.']);
  /* ученице открываем сразу */
  $u = userByEmail(inp('email'));
  /* Если нашли её прежнюю карточку — скажем об этом: человек должен видеть,
     что школа его узнала, а не гадать, куда делись прошлые встречи. */
  $privet = '';
  if(!empty($r['nashli']))
    $privet = 'Мы узнали вас по ' . $r['nashli']['kak'] .
              ($r['vstrech'] ? ' — ваши прошлые встречи (' . $r['vstrech'] . ') на месте.' : '.');
  ok(['pending' => false, 'privet' => $privet] + authPack($u));
}

if($action === 'auth_login'){
  if(loginBlocked()) fail('Слишком много попыток. Попробуйте через 15 минут.', 429);
  $r = userLogin(inp('email'), inp('pass'));
  if(isset($r['err'])){ loginFail(); fail($r['err'], 401); }
  loginOk();
  ok(authPack($r['user']));
}

if($action === 'auth_me'){
  $a = auth();
  if(!$a) fail('Нужен вход.', 401);
  if(!empty($a['user_id'])){
    $u = userById($a['user_id']);
    if(!$u || $u['status'] !== 'active') fail('Доступ закрыт.', 403);
    $p = authPack($u, false);
    /* Кураторам и владельцам — своя ссылка на бота, чтобы подключался
       каждый лично, а не общим кодом на всех. */
    if(in_array($u['role'], ['admin', 'owner'], true) && cfg('bot_user') !== ''){
      $p['me']['tgLink']  = botLink('u' . userTgKey($u));
      $p['me']['tgBound'] = !empty($u['tg_chat']);
    }
    ok($p);
  }
  /* Входа по кодам больше нет: токен без аккаунта — недействительный.
     Каждый вход именной, и доступ закрывается по одному человеку. */
  fail('Нужен вход. Войдите по почте и паролю.', 401);
}

if($action === 'auth_forgot'){
  $t = passResetMake(inp('email'));
  if($t !== ''){
    sendMail(inp('email'), 'Смена пароля',
      "Здравствуйте!\n\nЧтобы задать новый пароль, откройте ссылку:\n" .
      siteUrl() . "?reset=" . $t . "\n\nОна работает два часа. Если это были не вы — просто удалите письмо.");
  }
  ok(['sent' => true]);   /* ответ одинаковый: по форме нельзя проверять, кто у нас есть */
}

if($action === 'auth_reset'){
  $u = passResetUser(inp('t'));
  if(!$u) fail('Ссылка устарела. Запросите новую.', 403);
  $p = (string)inp('pass');
  if(mb_strlen($p) < 6) fail('Пароль — минимум шесть символов.');
  q("UPDATE shk_users SET pass=?, reset_token=NULL, reset_at=NULL WHERE id=?",
    [password_hash($p, PASSWORD_DEFAULT), $u['id']]);
  if($u['status'] !== 'active') ok(['pending' => true]);
  ok(authPack(userById($u['id'])));
}

/* ── Заявки на доступ ── */
if($action === 'users_list'){
  requireRole('owner');
  ok(['users' => all("SELECT id,email,name,role,status,expert_id,phone,tg,created_at,last_at
                        FROM shk_users ORDER BY (status='pending') DESC, created_at DESC"),
      'roles' => ROLES(),
      'experts' => all("SELECT id,name FROM shk_experts WHERE active=1 ORDER BY name")]);
}

if($action === 'user_approve'){
  requireRole('owner');
  $u = userById(inp('id'));
  if(!$u) fail('Не нашли этого человека.', 404);
  $eid = (int)inp('expertId');
  if($u['role'] === 'expert'){
    /* психолога связываем с карточкой: своей существующей или новой */
    if(!$eid){
      q("INSERT INTO shk_experts(name,pin,link,email,tg_key,active,created_at,surname) VALUES(?,?,?,?,?,1,?,?)",
        [$u['name'], (string)random_int(1000,9999), '', $u['email'],
         substr(bin2hex(random_bytes(8)),0,10), now(), surnameOf($u['name'])]);
      $eid = (int)db()->lastInsertId();
    }else{
      if(!expert($eid)) fail('Такой карточки нет.');
      q("UPDATE shk_experts SET email=? WHERE id=? AND (email='' OR email IS NULL)", [$u['email'], $eid]);
    }
  }
  q("UPDATE shk_users SET status='active', expert_id=? WHERE id=?", [$eid ?: null, $u['id']]);
  sendMail($u['email'], 'Доступ открыт',
    "Здравствуйте, " . $u['name'] . "!\n\nВаш доступ подтверждён — можно входить: " . siteUrl());
  ok();
}

/* Владелец меняет роль любому аккаунту. Себе — нельзя: случайно разжаловав
   единственного владельца, систему пришлось бы чинить руками в базе. */
if($action === 'user_role'){
  requireRole('owner');
  $me = auth();
  $u = userById((int)inp('id'));
  if(!$u) fail('Аккаунт не найден.', 404);
  if((int)$u['id'] === (int)($me['user_id'] ?? 0)) fail('Свою роль поменять нельзя — попросите второго владельца.');
  $rol = inp('role');
  if(!in_array($rol, ['client', 'expert', 'admin', 'owner'], true)) fail('Такой роли нет.');
  $eid = (int)($u['expert_id'] ?? 0);
  if($rol === 'expert' && !$eid){
    /* специалисту нужна карточка — заводим или цепляем существующую по имени/почте */
    $sv = one("SELECT id FROM shk_experts WHERE (email<>'' AND LOWER(email)=?) OR name=? LIMIT 1",
              [mb_strtolower($u['email']), $u['name']]);
    if($sv) $eid = (int)$sv['id'];
    else{
      q("INSERT INTO shk_experts(name,pin,link,email,direction,tg_key,active,created_at,surname) VALUES(?,?,?,?,?,?,1,?,?)",
        [$u['name'], (string)random_int(1000,9999), '', $u['email'], 'Психолог',
         substr(bin2hex(random_bytes(8)),0,10), now(), surnameOf($u['name'])]);
      $eid = (int)db()->lastInsertId();
    }
  }
  q("UPDATE shk_users SET role=?, expert_id=? WHERE id=?", [$rol, $rol === 'expert' ? $eid : ($eid ?: null), $u['id']]);
  /* живые входы несут роль в токене — обновляем, чтобы подействовало сразу */
  q("UPDATE shk_tokens SET role=?, expert_id=? WHERE user_id=?",
    [$rol, $rol === 'expert' ? $eid : null, $u['id']]);
  logit('access', 'Роль изменена: ' . $u['email'] . ' → ' . $rol);
  ok();
}

if($action === 'user_reject'){
  requireRole('owner');
  $u = userById(inp('id'));
  if(!$u) fail('Не нашли этого человека.', 404);
  q("UPDATE shk_users SET status='rejected' WHERE id=?", [$u['id']]);
  ok();
}

/* ═══════════ КАБИНЕТ СПЕЦИАЛИСТА ═══════════ */
if($action === 'expert_state'){
  $a = requireRole('expert');
  $e = expert($a['expert_id']);
  if(!$e) fail('Психолог не найден.', 404);
  $slots = all("SELECT id, dt, booking_id FROM shk_slots WHERE expert_id=? AND dt > ? ORDER BY dt",
               [$e['id'], date('Y-m-d H:i:s', time() - 86400)]);
  $bk = all("SELECT id, code, dt, client_name, contact, category, note, link, status, csat,
                    rep_at, rep_req, rep_plan, rep_next, paid, paid_sum, ckey, adm_note
               FROM shk_bookings WHERE expert_id=? AND status NOT IN ('cancelled','searching') ORDER BY dt", [$e['id']]);
  foreach($bk as &$row){
    $full = one("SELECT contact, phone, tg_nick, confirm, confirm_at, pair FROM shk_bookings WHERE id=?", [$row['id']]);
    $row = array_merge($row, $full ?: []);
    /* у старых записей телефон лежит в общем поле контакта — достаём оттуда */
    if(empty($row['phone'])){
      $p = phoneOf($row['contact'] ?? '');
      if($p !== '') $row['phone'] = '+' . $p;
    }
    if(empty($row['tg_nick']) && preg_match('/@([A-Za-z0-9_]{4,})/', (string)($row['contact'] ?? ''), $m))
      $row['tg_nick'] = $m[1];
  }
  unset($row);
  ok([
    'expert'   => ['id' => $e['id'], 'name' => $e['name'], 'link' => $e['link'],
                   'email' => (string)$e['email'], 'phone' => (string)($e['phone'] ?? ''),
                   'tgBound' => (bool)$e['tg_chat'], 'active' => (int)$e['active'],
                   'direction' => $e['direction'], 'topics' => topicsOf($e), 'pairs' => takesPairs($e),
                   'topicsSet' => !in_array(trim((string)$e['topics']), ['', '[]'], true)],
    'allTopics'=> TOPICS(),
    'tgLink'   => botLink('e' . $e['tg_key']),
    'slots'    => $slots,
    'bookings' => $bk,
    'quota'    => (int)cfg('quota'),
    'staff'    => staffMode(),
    'linksFree'=> linksFree($e['id']),
    /* Психологу важно знать, какая это встреча по счёту: последняя из
       положенных — повод договориться о продолжении заранее. */
    'counts'   => (function() use ($bk){
        $m = [];
        foreach($bk as $r){
          $k = (string)$r['ckey'];
          if($k === '' || isset($m[$k])) continue;
          $c = one("SELECT * FROM shk_clients WHERE ckey=?", [$k]);
          if($c) $m[$k] = ['used' => clientUsed($k), 'allowed' => clientAllowed($c)];
        }
        return $m;
      })(),
    /* Список учениц психолога — отдельно от встреч. Психологу нужно найти
       человека по фамилии или по курсу и увидеть, сколько встреч уже было,
       а не листать ленту записей за год. */
    'students' => (function() use ($bk){
        $m = [];
        foreach($bk as $r){
          $k = (string)$r['ckey'];
          if($k === '') continue;
          if(!isset($m[$k])){
            $c = one("SELECT * FROM shk_clients WHERE ckey=?", [$k]);
            $m[$k] = [
              'ckey' => $k, 'id' => $r['id'], 'name' => $r['client_name'], 'fio' => famFirst($r['client_name']),
              'course'  => $c['course'] ?? '', 'courses' => $c ? implode(', ', coursesOf($c)) : '',
              'phone'   => $c['phone'] ?? '', 'tg' => $c['tg'] ?? '', 'email' => $c['email'] ?? '',
              'contact' => $r['contact'] ?? '',
              'used' => $c ? clientUsed($k) : 0, 'allowed' => $c ? clientAllowed($c) : 0,
              'so_mnoy' => 0, 'last' => '', 'next' => '',
            ];
          }
          $m[$k]['so_mnoy']++;
          $m[$k]['id'] = $r['id'];   /* любая запись — чтобы открыть историю */
          if($r['dt'] <= now()){ if($r['dt'] > $m[$k]['last']) $m[$k]['last'] = $r['dt']; }
          else { if($m[$k]['next'] === '' || $r['dt'] < $m[$k]['next']) $m[$k]['next'] = $r['dt']; }
        }
        usort($m, function($a, $b){ return strcmp(fioKey($a['name']), fioKey($b['name'])); });
        return array_values($m);
      })(),
    'svodka'   => svodkaDni((int)$e['id']),
    'duration' => (int)cfg('duration'),
    'shared'   => sharedRoom(),
    'busy'     => sharedRoom() ? sharedBusy($e['id']) : [],
    'now'      => now(),
  ]);
}

/* Подтверждение прихода клиента — со стороны специалиста */
if($action === 'confirm_client'){
  $a = auth();
  if(!$a) fail('Нужен вход.', 401);
  $b = one("SELECT * FROM shk_bookings WHERE id=?", [(int)inp('id')]);
  if(!$b) fail('Встреча не найдена.', 404);
  if($a['role'] === 'expert' && (int)$a['expert_id'] !== (int)$b['expert_id']) fail('Это не ваша встреча.', 403);
  $val = inp('value') === 'no' ? 'no' : (inp('value') === '' ? '' : 'yes');
  q("UPDATE shk_bookings SET confirm=?, confirm_at=? WHERE id=?", [$val, $val === '' ? null : now(), $b['id']]);
  $e = expert($b['expert_id']);
  histAdd($b['id'], 'confirm', $val === 'yes' ? 'Специалист подтвердил приход клиента'
    : ($val === 'no' ? 'Специалист не дозвонился до клиента' : 'Отметка о подтверждении снята'));
  if($val === 'no') adminNotify("📵 Клиент не отвечает\n" . $b['client_name'] . ", " . fmtRu($b['dt']) .
    "\nСпециалист: " . ($e['name'] ?? '—') . (!empty($b['phone']) ? "\nТелефон: " . $b['phone'] : ''));
  ok();
}

if($action === 'slots_add'){
  $a      = requireRole('expert');
  $date   = inp('date');
  $times  = inp('times', []);
  $repeat = (int)inp('repeat', 1);
  if($repeat < 1) $repeat = 1;
  if($repeat > 14) $repeat = 14;
  if(!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) fail('Выберите день.');
  if(!is_array($times) || !$times) fail('Отметьте хотя бы один час.');
  $n = 0; $busy = 0;
  for($d = 0; $d < $repeat; $d++){
    $day = date('Y-m-d', strtotime($date . " +$d day"));
    foreach($times as $t){
      if(!preg_match('/^\d{2}:\d{2}$/', $t)) continue;
      $dt = "$day $t:00";
      if(ts($dt) < time()) continue;
      /* один общий кабинет: время занимает тот, кто открыл его первым */
      if(sharedRoom() && slotTakenByOther($dt, $a['expert_id'])){ $busy++; continue; }
      try{
        $st = q("INSERT IGNORE INTO shk_slots(expert_id, dt, created_at) VALUES(?,?,?)", [$a['expert_id'], $dt, now()]);
        $n += $st->rowCount();
      }catch(Throwable $e){}
    }
  }
  $called = $n ? notifyWaiting($a['expert_id']) : 0;
  if($n){
    /* Куратору: появилось куда записывать. Без этого он узнавал об открытых
       часах случайно и держал учениц в очереди на пустом месте. */
    $e = expert($a['expert_id']);
    $svobodno = (int)one("SELECT COUNT(*) c FROM shk_slots WHERE expert_id=? AND booking_id IS NULL AND dt>?",
                         [(int)$a['expert_id'], now()])['c'];
    adminNotify("🕒 " . ($e['name'] ?? 'Специалист') . " открыла время: +" . $n .
                "\nВсего свободных часов у неё: " . $svobodno .
                "\nМожно записывать учениц." .
                ($called ? "\nИз очереди позвали: " . $called : ''));
  }
  ok(['added' => $n, 'busy' => $busy, 'called' => $called]);
}

if($action === 'slot_del'){
  $a = requireRole('expert');
  q("DELETE FROM shk_slots WHERE id=? AND expert_id=? AND booking_id IS NULL", [(int)inp('id'), $a['expert_id']]);
  ok();
}

/* Психолог отменяет свою встречу. Чужую — нет. */
if($action === 'expert_cancel'){
  $a = requireRole('expert');
  $b = one("SELECT * FROM shk_bookings WHERE id=? AND expert_id=?", [(int)inp('id'), $a['expert_id']]);
  if(!$b) fail('Встреча не найдена.', 404);
  if($b['status'] === 'cancelled') fail('Эта встреча уже отменена.');
  if(!expertCancel($b, mb_substr(trim((string)inp('why')), 0, 190))) fail('Не получилось отменить.');
  ok();
}

if($action === 'links_add'){
  /* Пополнять запас может и психолог себе, и куратор за него: на пустой
     запас натыкается именно куратор — в момент подтверждения заявки. */
  $me = auth();
  if(!$me) fail('Нужен вход. Обновите страницу и войдите заново.', 401);
  if(in_array($me['role'], ['admin','owner'], true)){
    $eid = (int)inp('expertId');
    if(!$eid || !expert($eid)) fail('Не поняли, кому пополняем запас.');
    $a = ['expert_id' => $eid];
  }else{
    $a = requireRole('expert');
  }
  $raw = (string)inp('links');
  $added = 0; $bad = 0;
  foreach(preg_split('/[\s,;]+/', $raw) as $u){
    $u = trim($u);
    if($u === '') continue;
    if(!preg_match('~^https?://~i', $u)){ $bad++; continue; }
    try{
      $st = q("INSERT IGNORE INTO shk_links(expert_id,url,created_at) VALUES(?,?,?)",
              [$a['expert_id'], mb_substr($u, 0, 290), now()]);
      $added += $st->rowCount();
    }catch(Throwable $e){ $bad++; }
  }
  ok(['added' => $added, 'bad' => $bad, 'free' => linksFree($a['expert_id'])]);
}

if($action === 'save_link'){
  $a = requireRole('expert');
  $l = mb_substr(inp('link'), 0, 290);
  if($l !== '' && !preg_match('~^https?://~i', $l)) fail('Ссылка должна начинаться с https://');
  q("UPDATE shk_experts SET link=? WHERE id=?", [$l, $a['expert_id']]);
  /* будущие записи сразу переезжают на новую комнату */
  $moved = 0;
  foreach(all("SELECT * FROM shk_bookings WHERE expert_id=? AND status<>'cancelled' AND dt > ?",
              [$a['expert_id'], now()]) as $b){
    $before = $b['link'];
    $nb = syncMeetingLink($b);
    if($nb['link'] !== $before) $moved++;
  }
  ok(['moved' => $moved]);
}

if($action === 'save_topics'){
  $a = requireRole('expert');
  $t = inp('topics', []);
  if(!is_array($t)) $t = [];
  $t = array_values(array_intersect(TOPICS(), $t));
  q("UPDATE shk_experts SET topics=? WHERE id=?", [json_encode($t, JSON_UNESCAPED_UNICODE), $a['expert_id']]);
  $p = inp('pairs', null);
  if($p !== null) q("UPDATE shk_experts SET pairs=? WHERE id=?", [($p === '1' || $p === 1 || $p === true) ? 1 : 0, $a['expert_id']]);
  ok(['topics' => $t]);
}

/* Координатор может проставить парность за тех, кто ещё не заходил */
if($action === 'report'){
  $a = requireRole('expert');
  $b = one("SELECT * FROM shk_bookings WHERE id=? AND expert_id=?", [(int)inp('id'), $a['expert_id']]);
  if(!$b) fail('Встреча не найдена.', 404);
  $st = inp('status');
  if(!in_array($st, ['held', 'noshow', 'cancelled'], true)) $st = 'held';
  /* Быстрая отметка из сводки шлёт только исход. Раньше пустые поля затирали
     уже написанные заметки — теперь трогаем лишь то, что реально прислали. */
  q("UPDATE shk_bookings SET status=?, rep_at=? WHERE id=?", [$st, now(), $b['id']]);
  foreach(['req' => 'rep_req', 'plan' => 'rep_plan', 'next' => 'rep_next'] as $pole => $stolbec){
    if(inp($pole, null) !== null)
      q("UPDATE shk_bookings SET `$stolbec`=? WHERE id=?", [mb_substr(inp($pole), 0, 380), $b['id']]);
  }
  if(inp('paid', null) !== null)
    q("UPDATE shk_bookings SET paid=?, paid_sum=? WHERE id=?",
      [inp('paid') === 'yes' ? 1 : 0, (int)inp('sum'), $b['id']]);
  $e = expert($a['expert_id']);
  if(inp('paid') === 'yes') adminNotify("💰 Оплата: " . $b['client_name'] . " → " . $e['name'] . ", " . (int)inp('sum') . " ₽");
  ok();
}

/* ═══════════ КООРДИНАТОР ═══════════ */
if($action === 'admin_state'){
  requireRole('admin');
  $experts = bySurname(all("SELECT * FROM shk_experts"));
  $rows = [];
  foreach($experts as $e){
    $rows[] = [
      'id'       => $e['id'],
      'name'     => $e['name'],
      'pin'      => $e['pin'],
      'link'     => $e['link'],
      'active'   => (int)$e['active'],
      'direction'=> $e['direction'],
      'price'    => (int)$e['price'],
      'email'    => (string)$e['email'],
      'linksFree'=> linksFree($e['id']),
      'tgUser'   => $e['tg_user'],
      'onSite'   => (int)$e['on_site'],
      'pairs'    => takesPairs($e),
      'topics'   => topicsOf($e),
      'tgBound'  => (bool)$e['tg_chat'],
      'tgLink'   => botLink('e' . $e['tg_key']),
      'slots'    => (int)one("SELECT COUNT(*) c FROM shk_slots WHERE expert_id=? AND booking_id IS NULL AND dt>?", [$e['id'], now()])['c'],
      'load'     => loadOf($e['id']),
      'debt'     => reportDebt($e['id']),
    ];
  }
  /* Отдаём окно, а не всю историю: раньше за один заход приезжали все
     записи разом, и объём рос линейно. Старое достаётся поиском. */
  /* Шестьдесят дней покрывают всю живую работу: назначенное впереди,
     недавнее прошлое и хвост на отметку. Старое достаётся поиском. */
  $win  = date('Y-m-d 00:00:00', time() - 60*86400);
  /* Только те поля, которые рисует интерфейс: utm, вебхуки, zoom и прочая
     служебная требуха занимала половину ответа и не показывалась нигде. */
  /* Длинные тексты режем: в таблице они всё равно показываются коротко,
     а у перенесённых записей в «запросе» лежит целая строка старой
     таблицы — на ней одной ответ разбухал вдвое. */
  $cols = "b.id,b.code,b.expert_id,b.dt,b.client_name,b.contact,b.category,b.link,b.status,
           b.tg_chat,b.csat,b.nps,b.rep_at,b.paid,b.paid_sum,
           b.email,b.pair,b.phone,b.tg_nick,b.confirm,b.course,b.ckey,b.slot_id,
           LEFT(b.note,140) note, LEFT(b.comment,140) comment,
           LEFT(b.rep_req,100) rep_req, LEFT(b.rep_plan,100) rep_plan, LEFT(b.adm_note,140) adm_note";
  $bk   = all("SELECT $cols, e.name ename FROM shk_bookings b LEFT JOIN shk_experts e ON e.id=b.expert_id
                WHERE b.dt >= ? ORDER BY b.dt DESC", [$win]);
  /* Всё, что впереди, показываем всегда — даже если кто-то записан
     сильно заранее и в окно не попал. */
  $far = all("SELECT $cols, e.name ename FROM shk_bookings b LEFT JOIN shk_experts e ON e.id=b.expert_id
               WHERE b.dt > ? AND b.dt < ? ORDER BY b.dt DESC", [now(), $win]);
  if($far) $bk = array_merge($far, $bk);
  $hidden = (int)one("SELECT COUNT(*) c FROM shk_bookings WHERE dt < ? AND dt < ?", [$win, now()])['c'];
  $changed = [];
  foreach(all("SELECT booking_id FROM shk_history WHERE kind='expert'") as $h) $changed[(int)$h['booking_id']] = 1;
  foreach($bk as &$row){
    $row['changed'] = isset($changed[(int)$row['id']]);
    $ex = expert($row['expert_id']);
    $own = $ex ? trim((string)$ex['link']) : '';
    $l = (string)$row['link'];
    $row['linkKind'] = $l === '' ? 'none'
      : (($own !== '' && $l === $own) ? 'own'
      : (mb_strpos($l, 'zoom.us') !== false ? 'zoom'
      : (mb_strpos($l, 'jit.si') !== false ? 'jitsi' : 'other')));
    $row['phone'] = bookingPhone($row);
    $row['tgNick'] = $row['tg_nick'] ?? '';
    $row['confirm'] = $row['confirm'] ?? '';
  }
  unset($row);
  $hist = all("SELECT h.*, b.code, b.client_name FROM shk_history h
                 LEFT JOIN shk_bookings b ON b.id = h.booking_id
                ORDER BY h.id DESC LIMIT 40");

  /* сводка по составу: кто есть, кто работает, кому сколько должны */
  $rate = (int)cfg('rate');
  $team = ['total' => 0, 'withSlots' => 0, 'withTg' => 0, 'withHeld' => 0,
           'held' => 0, 'owe' => 0, 'rate' => $rate, 'norm' => (int)cfg('slot_norm'), 'rows' => []];
  foreach($experts as $ex){
    if(!$ex['active']) continue;
    $team['total']++;
    $slots = (int)one("SELECT COUNT(*) c FROM shk_slots WHERE expert_id=? AND dt > ?", [$ex['id'], date('Y-m-d H:i:s', time() - 30*86400)])['c'];
    $held  = (int)one("SELECT COUNT(*) c FROM shk_bookings WHERE expert_id=? AND status='held'", [$ex['id']])['c'];
    $paidSum = (int)one("SELECT COALESCE(SUM(paid_sum),0) s FROM shk_bookings WHERE expert_id=? AND paid=1", [$ex['id']])['s'];
    if($slots) $team['withSlots']++;
    if($ex['tg_chat']) $team['withTg']++;
    if($held){ $team['withHeld']++; $team['held'] += $held; }
    $team['owe'] += $held * $rate;
    /* Норма считается по календарному месяцу: куратор смотрит, сколько
       психолог выставил и сколько из этого дошло, и просит довыставить. */
    $mFrom = date('Y-m-01 00:00:00');
    $mTo   = date('Y-m-01 00:00:00', strtotime('first day of next month'));
    $mSlots = (int)one("SELECT COUNT(*) c FROM shk_slots WHERE expert_id=? AND dt>=? AND dt<?",
                       [$ex['id'], $mFrom, $mTo])['c'];
    $mHeld  = (int)one("SELECT COUNT(*) c FROM shk_bookings WHERE expert_id=? AND status='held' AND dt>=? AND dt<?",
                       [$ex['id'], $mFrom, $mTo])['c'];
    $mNo    = (int)one("SELECT COUNT(*) c FROM shk_bookings WHERE expert_id=? AND status='noshow' AND dt>=? AND dt<?",
                       [$ex['id'], $mFrom, $mTo])['c'];
    $mAhead = (int)one("SELECT COUNT(*) c FROM shk_bookings WHERE expert_id=? AND status='booked' AND dt>=? AND dt<?",
                       [$ex['id'], now(), $mTo])['c'];
    if($held || $slots || $mSlots) $team['rows'][] = [
      'id' => (int)$ex['id'], 'name' => $ex['name'], 'slots' => $slots, 'held' => $held,
      'own' => (int)$ex['price'], 'owe' => $held * $rate, 'paid' => $paidSum,
      'tg' => (bool)$ex['tg_chat'],
      'mSlots' => $mSlots, 'mHeld' => $mHeld, 'mNo' => $mNo, 'mAhead' => $mAhead,
      'mOwe' => $mHeld * $rate,
    ];
    $team['mOwe'] = ($team['mOwe'] ?? 0) + $mHeld * $rate;
  }
  usort($team['rows'], function($a, $b){ return $b['owe'] - $a['owe']; });
  /* свободные слоты — чтобы координатор записывал выбором, а не угадыванием */
  $freeSlots = all(
    "SELECT s.id, s.expert_id, s.dt, e.name ename FROM shk_slots s
       JOIN shk_experts e ON e.id = s.expert_id
      WHERE s.booking_id IS NULL AND e.active = 1 AND s.dt > ?
      ORDER BY s.dt LIMIT 400", [now()]);
  $pending = (int)one("SELECT COUNT(*) c FROM shk_outbox WHERE status='pending'")['c'];
  $s = settings();
  $me = auth();
  ok([
    'role'     => $me['role'],
    'experts'  => $rows,
    'bookings' => $bk,
    'hidden'   => $hidden,
    'times'    => openTimes(),
    'svodka'   => svodkaDni(0),
    /* Для переноса куратору нужен не просто свободный час, а чей он:
       переносить к тому же психологу — правило, а не исключение. */
    'free'     => all("SELECT s.dt, s.expert_id, e.name ename FROM shk_slots s
                         JOIN shk_experts e ON e.id = s.expert_id
                        WHERE s.booking_id IS NULL AND e.active = 1 AND s.dt > ?
                        ORDER BY s.dt LIMIT 400", [now()]),
    'settings' => [
      'quota' => (int)$s['quota'], 'duration' => (int)$s['duration'], 'admin_pin' => $s['admin_pin'],
      'expert_mode' => $s['expert_mode'], 'staff' => staffMode(),
      'client_limit' => $s['client_limit'], 'courses' => $s['courses'], 'email_req' => $s['email_req'],
      'moderation' => $s['moderation'], 'cooldown' => (int)$s['cooldown'], 'nudge' => $s['nudge'],
      'experts_by' => $s['experts_by'], 'slot_norm' => (int)$s['slot_norm'],
      'sticky' => $s['sticky'], 'ask_topic' => $s['ask_topic'], 'ask_pair' => $s['ask_pair'],
      'tz' => $s['tz'], 'auto_link' => $s['auto_link'], 'bot_user' => $s['bot_user'],
      'tg_on' => $s['tg_token'] !== '', 'relay_key' => $s['relay_key'],
      'remind24' => $s['remind24'], 'remind2' => $s['remind2'], 'remind15' => $s['remind15'], 'ask_csat' => $s['ask_csat'], 'org' => $s['org'],
      'alert_email' => $s['alert_email'], 'rate' => (int)$s['rate'],
      'room_mode' => $s['room_mode'], 'shared_link' => $s['shared_link'],
      'zoom_on' => $s['zoom_on'], 'zoom_account' => $s['zoom_account'], 'zoom_id' => $s['zoom_id'],
      'hook_on' => $s['hook_on'], 'hook_url' => $s['hook_url'],
      'sms_on' => $s['sms_on'], 'sms_email' => $s['sms_email'], 'sms_sign' => $s['sms_sign'],
      'sms_ready' => ($s['sms_email'] !== '' && $s['sms_key'] !== ''),
      'owner_pin' => $s['owner_pin'],
      'zoom_ready' => ($s['zoom_account'] !== '' && $s['zoom_id'] !== '' && $s['zoom_secret'] !== ''),
      'sheet_url' => $s['sheet_url'], 'sheet_at' => $s['sheet_at'],
    ],
    'waiting'  => all("SELECT id, code, name, contact, email, topic, pair, note, created_at, notified_at
                         FROM shk_wait WHERE status='waiting' ORDER BY created_at"),
    /* Сводка отказов за месяц — сразу в дашборд, чтобы её видели, а не искали */
    'refusals' => (function(){
        $from = date('Y-m-d 00:00:00', time() - 30*86400);
        $by = [];
        foreach(all("SELECT why, COUNT(*) c FROM shk_refusals WHERE at>=? GROUP BY why", [$from]) as $r)
          $by[$r['why']] = (int)$r['c'];
        return ['by' => $by, 'total' => array_sum($by), 'names' => REFUSALS()];
      })(),
    'adminTgLink' => botLink('a' . $s['admin_key']),
    'freeSlots'   => $freeSlots,
    'history'     => $hist,
    'team'        => $team,
    'queue'       => ['pending' => $pending, 'lastRelay' => cfg('last_relay'), 'lastTick' => cfg('last_tick')],
    'now'         => now(),
  ]);
}

/* Координатор заводит запись руками — например, клиентка написала в личку */
if($action === 'admin_book'){
  requireRole('admin');
  $when = str_replace('T', ' ', inp('dt'));
  if(!preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}(:\d{2})?$/', $when)) fail('Укажите дату и время.');
  if(strlen($when) === 16) $when .= ':00';
  $name  = mb_substr(inp('name'), 0, 100);
  $phone = mb_substr(inp('phone'), 0, 20);
  $tgn   = ltrim(mb_substr(inp('tg_nick'), 0, 60), '@');
  if(mb_strlen($name) < 2) fail('Впишите имя клиентки.');
  if($phone !== '' && phoneOf($phone) === '') fail('Телефон не разобрали. Формат: +7 999 123-45-67');
  if($phone === '' && $tgn === '') fail('Нужен хотя бы один контакт — телефон или Telegram.');
  $contact = trim(($phone !== '' ? $phone : '') . ($phone !== '' && $tgn !== '' ? ', ' : '') . ($tgn !== '' ? '@' . $tgn : ''));
  if(ts($when) < time())      fail('Это время уже прошло.');

  $force  = (int)inp('expertId');
  $b = makeBooking($when, $name, $contact, mb_substr(inp('category'), 0, 100), mb_substr(inp('note'), 0, 300),
                   $force ?: null, true, mb_substr(inp('direction'), 0, 60), mb_substr(inp('email'), 0, 110), [],
                   inp('pair') === '1', $phone, $tgn);
  if(!$b){
    fail($force
      ? 'У этого специалиста время занято или его нет в пуле. Выберите другое время или другого человека.'
      : 'На это время нет ни одного свободного слота. Выберите специалиста явно — слот откроется под него.');
  }
  $e = expert($b['expert_id']);
  ok(['booking' => [
    'code' => $b['code'], 'dt' => $b['dt'], 'expert' => $e['name'],
    'link' => $b['link'], 'tg' => botLink('b' . $b['code']),
    'sms'  => (empty($b['tg_chat']) && smsOn() && bookingPhone($b) !== ''),
  ]]);
}

if($action === 'admin_cancel'){
  requireRole('admin');
  $b = one("SELECT * FROM shk_bookings WHERE id=?", [(int)inp('id')]);
  if(!$b) fail('Запись не найдена.', 404);
  cancelBooking($b, 'координатор');
  ok();
}

/* Проверка сквозная: аккаунт → создать → перенести → удалить.
   Так сразу видно, каких прав не хватает, и следов в Zoom не остаётся. */
/* Удаление записи насовсем — для тестовых и ошибочных.
   Тихое: никому не пишем, это уборка, а не отмена встречи. */
if($action === 'booking_delete'){
  requireRole('admin');
  $b = one("SELECT * FROM shk_bookings WHERE id=?", [(int)inp('id')]);
  if(!$b) fail('Запись не найдена.', 404);
  if(!empty($b['zoom_id'])) zoomDrop($b['zoom_id']);
  q("UPDATE shk_slots SET booking_id=NULL WHERE booking_id=?", [$b['id']]);
  q("DELETE FROM shk_sent WHERE booking_id=?", [$b['id']]);
  q("UPDATE shk_tg SET booking_id=NULL WHERE booking_id=?", [$b['id']]);
  q("DELETE FROM shk_bookings WHERE id=?", [$b['id']]);
  logit('admin', 'удалена запись ' . $b['code'] . ' — ' . $b['client_name']);
  ok();
}

/* Перевыпуск ссылки на встречу: старая перестаёт работать,
   поэтому сразу пробуем донести новую всем, до кого дотягиваемся. */
if($action === 'unassign_booking'){
  requireRole('admin');
  $b = one("SELECT * FROM shk_bookings WHERE id=?", [(int)inp('id')]);
  if(!$b) fail('Запись не найдена.', 404);
  list($ok, $res) = unassignBooking($b, mb_substr(inp('reason'), 0, 200));
  if(!$ok) fail($res);
  ok();
}

if($action === 'assign_booking'){
  requireRole('admin');
  $b = one("SELECT * FROM shk_bookings WHERE id=?", [(int)inp('id')]);
  if(!$b) fail('Запись не найдена.', 404);
  $r = assignBooking($b, (int)inp('expertId'));
  if(!$r[0]) fail($r[1]);
  $e = expert($r[1]['expert_id']);
  ok(['expert' => $e['name'], 'link' => $r[1]['link'], 'via' => $r[2] ?? '']);
}

if($action === 'change_expert'){
  requireRole('admin');
  $b = one("SELECT * FROM shk_bookings WHERE id=?", [(int)inp('id')]);
  if(!$b) fail('Запись не найдена.', 404);
  if($b['status'] === 'cancelled') fail('Запись отменена — менять специалиста не в чем.');
  list($ok, $res) = changeExpert($b, (int)inp('expertId'), mb_substr(inp('reason'), 0, 200));
  if(!$ok) fail($res);
  $e = expert($res['expert_id']);
  ok(['expert' => $e['name'], 'dt' => $res['dt'], 'link' => $res['link']]);
}

/* Полная история по одной записи: что уходило, каким каналом, что менялось */
if($action === 'booking_log'){
  requireRole('admin');
  $b = one("SELECT * FROM shk_bookings WHERE id=?", [(int)inp('id')]);
  if(!$b) fail('Запись не найдена.', 404);
  $e = expert($b['expert_id']);
  $names = [
    'r24'  => 'Напоминание ученице за сутки',
    'r2'   => 'Напоминание ученице за 2 часа',
    'r15'  => 'Напоминание ученице за 15 минут',
    'ask'  => 'Просьба оценить встречу',
    'e2'   => 'Напоминание психологу за 2 часа',
    'eask' => 'Вопрос психологу: как прошла',
    'rep'  => 'Напоминание психологу про отчёт',
  ];
  /* Пустой канал — не «забыли записать», а «сознательно не отправляли»:
     так система гасит напоминания по встречам, чьё время уже прошло
     (перенос из старых таблиц, позднее подтверждение, перенос встречи).
     Живая отправка всегда проставляет канал, хотя бы 'none'. */
  $via = ['tg' => 'Telegram', 'sms' => 'SMS', 'mail' => 'почта',
          'none' => 'не удалось доставить', '' => 'не отправляли — время уже прошло'];
  $ev = [];
  $ev[] = ['at' => $b['created_at'], 'what' => 'Запись создана', 'how' => $e ? ($e['name']) : ''];
  foreach(all("SELECT * FROM shk_sent WHERE booking_id=?", [$b['id']]) as $x)
    $ev[] = ['at' => $x['at'], 'what' => $names[$x['kind']] ?? $x['kind'], 'how' => $via[$x['via'] ?? ''] ?? $x['via']];
  foreach(all("SELECT * FROM shk_history WHERE booking_id=?", [$b['id']]) as $x)
    $ev[] = ['at' => $x['at'], 'what' => $x['txt'], 'how' => ''];
  if($b['csat_at']) $ev[] = ['at' => $b['csat_at'], 'what' => 'Ученица оценила встречу на ' . $b['csat'] . ' из 5', 'how' => $b['comment'] ? '«' . $b['comment'] . '»' : ''];
  if($b['rep_at'])  $ev[] = ['at' => $b['rep_at'],  'what' => 'Психолог сдал отчёт', 'how' => $b['rep_plan']];
  usort($ev, function($x, $y){ return strcmp($x['at'], $y['at']); });
  ok(['events' => $ev]);
}

if($action === 'admin_move'){
  requireRole('admin');
  $b = one("SELECT * FROM shk_bookings WHERE id=?", [(int)inp('id')]);
  if(!$b) fail('Запись не найдена.', 404);
  if($b['status'] === 'cancelled') fail('Эта запись отменена — переносить нечего.');
  if(ts($b['dt']) < time()) fail('Встреча уже прошла — переносить нечего.');
  $when = str_replace('T', ' ', inp('dt'));
  if(!preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}(:\d{2})?$/', $when)) fail('Не разобрали новое время.');
  if(strlen($when) === 16) $when .= ':00';
  if(ts($when) < time()) fail('Это время уже прошло. Выберите другое.');
  if($when === $b['dt'])  fail('Это то же самое время.');
  $nb = rescheduleBooking($b, $when, 'координатор');
  if(!$nb) fail('Это время только что заняли. Выберите другое — старое место пока за записью.');
  ok(['dt' => $nb['dt'], 'code' => $nb['code']]);
}

if($action === 'booking_link'){
  $a = auth();
  if(!$a) fail('Нужен вход. Обновите страницу и войдите заново.', 401);
  $b = one("SELECT * FROM shk_bookings WHERE id=?", [(int)inp('id')]);
  if(!$b) fail('Запись не найдена.', 404);
  /* Психолог правит только свои встречи, координатор — любые. */
  $svoya = ($a['role'] === 'expert' && (int)$a['expert_id'] === (int)$b['expert_id']);
  if(!$svoya && !in_array($a['role'], ['admin', 'owner'], true)) fail('Это не ваша встреча.', 403);
  if($b['status'] === 'cancelled') fail('Встреча отменена — ссылка ей уже не нужна.');

  $link = trim((string)inp('link'));
  if($link === '') fail('Вставьте ссылку на встречу.');
  if(!preg_match('~^https?://~i', $link)) fail('Ссылка должна начинаться с http:// или https://');
  $link = mb_substr($link, 0, 290);
  $bylo = trim((string)$b['link']);
  q("UPDATE shk_bookings SET link=? WHERE id=?", [$link, $b['id']]);
  $b['link'] = $link;

  $kto = $svoya ? 'психолог' : 'координатор';
  histAdd($b['id'], 'link', ($bylo === '' ? 'Вписана ссылка на встречу (' : 'Ссылка заменена (') . $kto . ')');

  /* Ученице обещали «пришлём отдельно» — держим слово сразу, как только
     ссылка появилась. Если меняем уже выданную, тоже сообщаем: по старой
     она не зайдёт. */
  $e = expert($b['expert_id']);
  $tema = $bylo === '' ? 'Ссылка на встречу — ' : 'Новая ссылка на встречу — ';
  if(!empty($b['email']))
    sendMail($b['email'], $tema . fmtRu($b['dt']),
      "Здравствуйте, " . $b['client_name'] . "!\n\n" . meetingCard($b, $e) . "\n\n" .
      ($bylo === '' ? "" : "Прежняя ссылка больше не работает.\n") .
      "Перенести или отменить: " . siteUrl() . "?code=" . $b['code']);
  if(!empty($b['tg_chat']))
    send($b['tg_chat'], ($bylo === '' ? "Ссылка на встречу " : "Новая ссылка на встречу ") .
      fmtRu($b['dt']) . ":\n" . $link);
  ok(['link' => $link]);
}

if($action === 'reissue_link'){
  requireRole('admin');
  $b = one("SELECT * FROM shk_bookings WHERE id=?", [(int)inp('id')]);
  if(!$b) fail('Запись не найдена.', 404);
  if($b['status'] === 'cancelled') fail('Запись отменена — перевыпускать нечего.');
  $ex0 = expert($b['expert_id']);
  if($ex0 && ownRoom($ex0)) fail('У этого специалиста своя комната — ссылка постоянная, перевыпускать нечего.');
  if(!zoomOn()) fail('Автосоздание конференций выключено — ссылку выдаёт специалист.');

  $old = $b['link'];
  if(!empty($b['zoom_id'])) zoomDrop($b['zoom_id']);
  q("UPDATE shk_bookings SET zoom_id='', link='' WHERE id=?", [$b['id']]);
  $b['zoom_id'] = ''; $b['link'] = '';
  $b = attachZoom($b);
  if($b['link'] === '' || $b['link'] === $old) fail('Zoom не выдал новую ссылку. Попробуйте ещё раз.');

  $e = expert($b['expert_id']);
  $txt = "🔗 Новая ссылка на встречу\n" . fmtRu($b['dt']) . "\n" . $b['link'] .
         "\n\nСтарая ссылка больше не работает — откройте эту.";
  $toClient = tell($b['tg_chat'], $b['email'], 'Новая ссылка на встречу ' . fmtRu($b['dt']), $txt);
  $toExpert = tell($e['tg_chat'], $e['email'] ?? '', 'Новая ссылка на встречу ' . fmtRu($b['dt']),
    $txt . "\nКлиент: " . $b['client_name']);

  ok([
    'link'   => $b['link'],
    'client' => $toClient ?: 'нет канала',
    'expert' => $toExpert ?: 'нет канала',
    'text'   => 'Здравствуйте, ' . $b['client_name'] . '! Ссылка на встречу ' . fmtRu($b['dt']) .
                ' изменилась, вот новая: ' . $b['link'] . ' Старая больше не работает.',
  ]);
}

/* Кто подключён к боту и кем является */
if($action === 'tg_list'){
  requireRole('admin');
  $rows = [];
  foreach(all("SELECT * FROM shk_tg ORDER BY updated_at DESC") as $r){
    $rows[] = [
      'chat'   => (string)$r['chat'],
      'role'   => $r['role'],
      'uname'  => $r['uname'] ?? '',
      'fname'  => $r['fname'] ?? '',
      'expert' => $r['expert_id'] ? (expert($r['expert_id'])['name'] ?? '') : '',
      'at'     => $r['updated_at'],
    ];
  }
  ok(['list' => $rows, 'adminKey' => cfg('admin_key')]);
}

if($action === 'tg_role'){
  requireRole('admin');
  $chat = (string)inp('chat');
  $role = inp('role');
  if(!in_array($role, ['admin', 'client', 'expert'], true)) fail('Неизвестная роль.');
  $cur = one("SELECT * FROM shk_tg WHERE chat=?", [$chat]);
  if(!$cur) fail('Такое подключение не найдено.', 404);
  if($role === 'admin'){
    /* был специалистом — отвязываем от карточки специалиста */
    if($cur['expert_id']) q("UPDATE shk_experts SET tg_chat=NULL WHERE id=?", [$cur['expert_id']]);
    q("UPDATE shk_tg SET role='admin', expert_id=NULL, booking_id=NULL, state='' WHERE chat=?", [$chat]);
    send($chat, "✅ Вам открыли доступ куратора.\n\nСюда будут приходить все новые записи, отмены, неявки, оплаты и оценки ниже 4.");
  }else{
    q("UPDATE shk_tg SET role=? WHERE chat=?", [$role, $chat]);
  }
  ok();
}

if($action === 'tg_unbind'){
  requireRole('admin');
  $chat = (string)inp('chat');
  $cur = one("SELECT * FROM shk_tg WHERE chat=?", [$chat]);
  if($cur && $cur['expert_id']) q("UPDATE shk_experts SET tg_chat=NULL WHERE id=?", [$cur['expert_id']]);
  q("UPDATE shk_bookings SET tg_chat=NULL WHERE tg_chat=?", [$chat]);
  q("DELETE FROM shk_tg WHERE chat=?", [$chat]);
  ok();
}

if($action === 'test_mail'){
  requireRole('owner');
  $to = inp('email');
  if($to !== '') setSetting('alert_email', $to);
  $sent = alertMail('Проверка связи — запись на консультацию',
    "Это тестовое письмо. Если вы его читаете, тревога о застрявшей очереди дойдёт.\n\n" .
    "Проверьте и папку «Спам»: письма с общего хостинга туда попадают часто. Если письмо там — " .
    "отметьте «не спам», иначе настоящую тревогу вы не увидите.");
  if(!$sent) fail('Сервер не смог отправить письмо. Проверьте адрес.');
  ok();
}

if($action === 'expert_dir'){
  requireRole(expertsBy());
  q("UPDATE shk_experts SET direction=? WHERE id=?", [mb_substr(trim(inp('dir')), 0, 60), (int)inp('id')]);
  ok();
}

if($action === 'expert_add'){
  requireRole(expertsBy());
  $n = mb_substr(inp('name'), 0, 110);
  $p = mb_substr(inp('pin'), 0, 20);
  $l = mb_substr(inp('link'), 0, 290);
  $em = mb_substr(trim((string)inp('email')), 0, 110);
  if(mb_strlen($n) < 2) fail('Впишите имя.');
  if($em !== '' && !filter_var($em, FILTER_VALIDATE_EMAIL)) fail('Проверьте почту специалиста.');
  if(mb_strlen($p) < 3) fail('Код доступа — минимум три символа.');
  /* «Кто это» — психолог, сексолог, коуч: ученица видит это при записи */
  $dir = mb_substr(trim((string)inp('dir')), 0, 60) ?: 'Психолог';
  q("INSERT INTO shk_experts(name,pin,link,email,direction,tg_key,active,created_at,surname) VALUES(?,?,?,?,?,?,1,?,?)",
    [$n, $p, $l, $em, $dir, substr(bin2hex(random_bytes(8)), 0, 10), now(), surnameOf($n)]);
  ok(['id' => db()->lastInsertId()]);
}

/* Почту психолога можно вписать позже: без неё система не может
   ни напомнить о завтрашних встречах, ни предупредить, что ссылки кончились. */
if($action === 'expert_email'){
  requireRole(expertsBy());
  $em = mb_substr(trim((string)inp('email')), 0, 110);
  if($em !== '' && !filter_var($em, FILTER_VALIDATE_EMAIL)) fail('Проверьте почту.');
  q("UPDATE shk_experts SET email=? WHERE id=?", [$em, (int)inp('id')]);
  ok();
}

if($action === 'expert_me_save'){
  $a = requireRole('expert');
  $em = mb_strtolower(trim((string)inp('email')));
  if($em !== '' && !filter_var($em, FILTER_VALIDATE_EMAIL)) fail('Проверьте адрес почты.');
  $tel = phoneOf(inp('phone'));
  if(trim((string)inp('phone')) !== '' && $tel === '') fail('Не разобрали телефон. Формат: +7 999 123-45-67');
  q("UPDATE shk_experts SET email=?, phone=? WHERE id=?", [$em, $tel, (int)$a['expert_id']]);
  /* Если психолог вошёл под своим аккаунтом — держим связь в обоих местах:
     на карточку приходят уведомления, в аккаунт — восстановление пароля. */
  if(!empty($a['user_id'])) q("UPDATE shk_users SET phone=? WHERE id=?", [$tel, (int)$a['user_id']]);
  ok();
}

/* Специалист правит число встреч своей ученице. Право узкое: только тем,
   кто к нему записывался, — чужих учениц он и не видит, и не трогает. */
if($action === 'expert_set_allowed'){
  $a = requireRole('expert');
  $ck = (string)inp('ckey');
  if($ck === '') fail('Не поняла, о ком речь. Обновите страницу.');
  $svoya = one("SELECT id FROM shk_bookings WHERE expert_id=? AND ckey=? LIMIT 1", [(int)$a['expert_id'], $ck]);
  if(!$svoya) fail('Это не ваша ученица.', 403);
  $c = clientFind($ck);
  if(!$c) fail('Карточка не найдена.', 404);
  $chislo = max(0, min(99, (int)inp('allowed')));
  q("UPDATE shk_clients SET allowed=? WHERE id=?", [$chislo, $c['id']]);
  $e = expert($a['expert_id']);
  logit('client', 'Специалист ' . ($e['name'] ?? '') . ' изменил число встреч: ' . $c['name'] . ' → ' . $chislo);
  ok();
}

if($action === 'expert_toggle'){
  requireRole(expertsBy());
  q("UPDATE shk_experts SET active = 1 - active WHERE id=?", [(int)inp('id')]);
  ok();
}

if($action === 'expert_del'){
  requireRole(expertsBy());
  $id = (int)inp('id');
  if(one("SELECT id FROM shk_bookings WHERE expert_id=? AND status<>'cancelled'", [$id]))
    fail('У специалиста есть записи — удалять нельзя, скройте её из пула.');
  q("DELETE FROM shk_slots WHERE expert_id=? AND booking_id IS NULL", [$id]);
  q("DELETE FROM shk_experts WHERE id=?", [$id]);
  ok();
}

/* Курсы: список, стартовое число консультаций, добавление и удаление.
   Правят и куратор, и владелец — это операционная работа с ученицами. */
if($action === 'courses_list'){
  requireRole('admin');
  $out = [];
  foreach(COURSES() as $k => $v){
    $out[] = ['name' => $k, 'sessions' => (int)$v,
      'students' => (int)one("SELECT COUNT(*) c FROM shk_clients WHERE course=?", [$k])['c']];
  }
  ok(['courses' => $out]);
}

if($action === 'courses_save'){
  requireRole('admin');
  $vhod = inp('courses', []);
  if(!is_array($vhod) || !$vhod) fail('Список курсов пуст — так нельзя: ученицам нечего будет выбирать.');
  $stroki = []; $imena = [];
  foreach($vhod as $r){
    $imya = mb_substr(trim((string)($r['name'] ?? '')), 0, 110);
    $imya = str_replace(['=', "\n"], '', $imya);
    if($imya === '' || isset($imena[$imya])) continue;
    $imena[$imya] = 1;
    $stroki[] = $imya . ' = ' . max(0, min(99, (int)($r['sessions'] ?? 1)));
  }
  if(!$stroki) fail('Не разобрали ни одного курса.');
  setSetting('courses', implode("\n", $stroki));
  logit('api', 'Курсы обновлены (' . count($stroki) . ')');
  ok();
}

if($action === 'settings_save'){
  requireRole('owner');
  /* Поля, которые можно осознанно очистить: пустая строка — это значение, а не «не трогать» */
  $clearable = ['shared_link' => 1, 'alert_email' => 1, 'hook_url' => 1];
  foreach(['quota','expert_mode','moderation','cooldown','nudge','email_req','client_limit','courses','experts_by','slot_norm','duration','tz','auto_link','remind24','remind2','remind15','ask_csat','org','alert_email','sticky','choose_expert',
           'room_mode','shared_link','sheet_url','zoom_on','zoom_account','zoom_id','ask_topic','ask_pair',
           'hook_on','hook_url','rate','sms_on','sms_email','sms_sign'] as $k){
    $v = inp($k, null);
    if($v === null) continue;
    if($v === '' && !isset($clearable[$k])) continue;
    setSetting($k, $v);
  }
  $sk = inp('sms_key');
  if($sk !== '') setSetting('sms_key', $sk);
  $zs = inp('zoom_secret');
  if($zs !== '' && $zs !== '••••••') setSetting('zoom_secret', $zs);
  if(inp('zoom_account') !== '' || $zs !== '') { setSetting('zoom_token',''); setSetting('zoom_token_exp','0'); }
  $pin = inp('admin_pin');
  if($pin !== '' && mb_strlen($pin) >= 4) setSetting('admin_pin', $pin);
  $op = inp('owner_pin');
  if($op !== '' && mb_strlen($op) >= 4) setSetting('owner_pin', $op);
  $tok = inp('tg_token');
  if($tok !== '') setSetting('tg_token', $tok);
  if(inp('tg_clear') === '1'){ setSetting('tg_token', ''); setSetting('bot_user', ''); }
  $bu = inp('bot_user');
  if($bu !== '') setSetting('bot_user', ltrim($bu, '@'));
  ok();
}

if($action === 'csv'){
  requireRole('admin');
  header('Content-Type: text/csv; charset=utf-8');
  header('Content-Disposition: attachment; filename="zapisi-' . date('Y-m-d') . '.csv"');
  $out = fopen('php://output', 'w');
  fwrite($out, "\xEF\xBB\xBF");
  fputcsv($out, ['Когда','Ученица','Курс','Телефон','Telegram','Почта','Запрос','Психолог','Статус',
                 'От куратора','С чем пришла','Что предложили','CSAT','Код','Создана'], ';');
  foreach(all("SELECT b.*, e.name ename FROM shk_bookings b LEFT JOIN shk_experts e ON e.id=b.expert_id ORDER BY b.dt") as $b){
    fputcsv($out, [$b['dt'], $b['client_name'], $b['course'], $b['phone'], $b['tg_nick'], $b['email'],
      $b['note'], $b['ename'], $b['status'], $b['adm_note'], $b['rep_req'], $b['rep_plan'],
      $b['csat'], $b['code'], $b['created_at']], ';');
  }
  fclose($out);
  exit;
}

/* Выгрузки, за которыми ходят к руководителю: кто сколько израсходовал,
   кому сколько платить и кто ждёт очереди. */
if($action === 'csv_clients'){
  requireRole('admin');
  header('Content-Type: text/csv; charset=utf-8');
  header('Content-Disposition: attachment; filename="uchenicy-' . date('Y-m-d') . '.csv"');
  $out = fopen('php://output', 'w'); fwrite($out, "\xEF\xBB\xBF");
  /* Отдельные колонки телефона и телеграма: в общей «Связи» они смешаны,
     а в таблице по ним нужно фильтровать и делать рассылку. */
  fputcsv($out, ['Ученица','Курс','Курс со слов ученицы','Телефон','Телеграм','Почта','Связь',
                 'Использовано','Положено','Осталось','Последняя встреча',
                 'Запись закрыта','Почему','Заметка куратора','Заведена'], ';');
  $spis = all("SELECT * FROM shk_clients");
  usort($spis, function($a, $b){ return strcmp(fioKey($a['name']), fioKey($b['name'])); });
  foreach($spis as $c){
    $used = clientUsed($c['ckey']); $all = clientAllowed($c);
    $last = one("SELECT MAX(dt) d FROM shk_bookings WHERE ckey=? AND status<>'cancelled'", [$c['ckey']])['d'] ?? '';
    fputcsv($out, [famFirst($c['name']), $c['course'], implode(', ', coursesOf($c)),
      $c['phone'] ?? '', ($c['tg'] ?? '') !== '' ? '@' . $c['tg'] : '', $c['email'], $c['contact'],
      $used, $all ?: '', $all ? max(0, $all - $used) : '', $last ? substr($last, 0, 16) : '',
      (int)$c['blocked'] ? 'да' : '', $c['blocked_why'], $c['note'], substr((string)$c['created_at'], 0, 10)], ';');
  }
  fclose($out); exit;
}

if($action === 'csv_pay'){
  requireRole('admin');
  $m = preg_match('/^\d{4}-\d{2}$/', (string)inp('month')) ? inp('month') : date('Y-m');
  $from = $m . '-01 00:00:00';
  $to   = date('Y-m-01 00:00:00', strtotime($from . ' +1 month'));
  $rate = (int)cfg('rate');
  header('Content-Type: text/csv; charset=utf-8');
  header('Content-Disposition: attachment; filename="vyplaty-' . $m . '.csv"');
  $out = fopen('php://output', 'w'); fwrite($out, "\xEF\xBB\xBF");
  fputcsv($out, ['Психолог','Проведено','Не пришли','Ставка, ₽','К выплате, ₽'], ';');
  $total = 0;
  foreach(all("SELECT * FROM shk_experts WHERE active=1 ORDER BY name") as $e){
    $held = (int)one("SELECT COUNT(*) c FROM shk_bookings WHERE expert_id=? AND status='held' AND dt>=? AND dt<?",
                     [$e['id'], $from, $to])['c'];
    $no   = (int)one("SELECT COUNT(*) c FROM shk_bookings WHERE expert_id=? AND status='noshow' AND dt>=? AND dt<?",
                     [$e['id'], $from, $to])['c'];
    $total += $held * $rate;
    fputcsv($out, [$e['name'], $held, $no, $rate, $held * $rate], ';');
  }
  fputcsv($out, ['Итого', '', '', '', $total], ';');
  fclose($out); exit;
}

if($action === 'csv_wait'){
  requireRole('admin');
  header('Content-Type: text/csv; charset=utf-8');
  header('Content-Disposition: attachment; filename="ochered-' . date('Y-m-d') . '.csv"');
  $out = fopen('php://output', 'w'); fwrite($out, "\xEF\xBB\xBF");
  fputcsv($out, ['Кто','Связь','Почта','Тема','Ждёт с','Позвали'], ';');
  foreach(all("SELECT * FROM shk_wait WHERE status='waiting' ORDER BY created_at") as $w)
    fputcsv($out, [$w['name'], $w['contact'], $w['email'], $w['topic'], $w['created_at'], $w['notified_at']], ';');
  fclose($out); exit;
}

/* ═══════════ РЕЛЕ TELEGRAM ═══════════ */
if($action === 'relay'){
  $key = inp('key');
  if($key === '' || !hash_equals((string)cfg('relay_key'), $key)) fail('Нет доступа.', 403);
  setSetting('last_relay', now());

  /* подтверждения отправки */
  foreach((array)inp('ack', []) as $id) q("UPDATE shk_outbox SET status='sent', sent_at=? WHERE id=?", [now(), (int)$id]);
  foreach((array)inp('failed', []) as $f){
    q("UPDATE shk_outbox SET tries=tries+1, err=?, status=IF(tries>=4,'failed','pending') WHERE id=?",
      [mb_substr((string)($f['err'] ?? 'error'), 0, 280), (int)($f['id'] ?? 0)]);
  }
  /* входящие апдейты */
  foreach((array)inp('updates', []) as $u){
    try{ handleUpdate($u); }catch(Throwable $e){ logit('tg', $e->getMessage()); }
  }
  if(($off = inp('offset', null)) !== null && $off !== '') setSetting('tg_offset', (string)(int)$off);

  try{ tick(); }catch(Throwable $e){ logit('tick', $e->getMessage()); }

  $msgs = all("SELECT id, chat, text, kb FROM shk_outbox WHERE status='pending' ORDER BY id LIMIT 25");
  foreach($msgs as &$m){ $m['kb'] = $m['kb'] ? json_decode($m['kb'], true) : null; }
  ok(['token' => cfg('tg_token'), 'messages' => $msgs, 'offset' => (int)cfg('tg_offset'), 'bot' => cfg('bot_user')]);
}

if($action === 'relay_bot'){
  $key = inp('key');
  if($key === '' || !hash_equals((string)cfg('relay_key'), $key)) fail('Нет доступа.', 403);
  $u = ltrim(inp('username'), '@');
  if($u !== '') setSetting('bot_user', $u);
  ok();
}

fail('Неизвестное действие.', 404);

}catch(Throwable $e){
  logit('api', $e->getMessage());
  fail('Сервер споткнулся: ' . $e->getMessage(), 500);
}
