<?php
/* API сопровождения. Все ответы — JSON. */
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

/* Ученицу узнаём или по токену входа, или по личной ссылке из письма (?k=…) —
   как в школе: письмо со ссылкой пускает без пароля. */
function clientOfRequest(){
  $a = auth();
  if($a && $a['user_id']){
    $u = userById($a['user_id']);
    if($u && $u['status'] === 'active' && $u['role'] === 'client') return clientForUser($u);
  }
  $c = clientByKkey(inp('k'));
  return $c ?: null;
}

try{

/* ═══════════ ОБЩЕЕ ═══════════ */
if($action === 'boot'){
  /* адрес сайта запоминаем сами — он нужен в письмах и ссылках */
  $self = (isset($_SERVER['HTTPS']) ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? '') .
          preg_replace('~api\.php.*$~', '', (string)($_SERVER['REQUEST_URI'] ?? ''));
  if($self !== cfg('site_url') && strlen($self) > 12) setSetting('site_url', $self);
  $recM = managerByRecKey(inp('rec'));
  ok([
    'org'      => cfg('org'),
    'tz'       => cfg('tz'),
    'duration' => (int)cfg('duration'),
    'roles'    => ROLES(),
    'times'    => freeTimes(30, $recM ? $recM['tg_key'] : ''),
    'bot'      => cfg('bot_user'),
    'now'      => now(),
    'site'     => siteUrl(),
    'recManager' => $recM ? $recM['name'] : null,
  ]);
}

/* ═══════════ ВХОД (как в школе: почта и пароль у всех) ═══════════ */
if($action === 'register'){
  if(loginBlocked()) fail('Слишком много попыток. Подождите 15 минут.', 429);
  $r = userRegister(inp('email'), (string)inp('pass'), inp('name'), inp('role'), inp('phone'), inp('tg'));
  if(isset($r['err'])){ loginFail(); fail($r['err']); }
  loginOk();
  if($r['status'] === 'active'){
    $u = userByEmail(inp('email'));
    ok(authPack($u) + ['status' => 'active']);
  }
  ok(['status' => 'pending',
      'msg' => 'Владелец подтвердит доступ, и можно будет войти. Мы напишем на почту.']);
}

if($action === 'login'){
  if(loginBlocked()) fail('Слишком много попыток. Подождите 15 минут.', 429);
  $r = userLogin(inp('email'), (string)inp('pass'));
  if(isset($r['err'])){ loginFail(); fail($r['err']); }
  loginOk();
  ok(authPack($r['user']));
}

if($action === 'me'){
  $a = auth();
  $u = $a && $a['user_id'] ? userById($a['user_id']) : null;
  if(!$u || $u['status'] !== 'active') fail('Нужен вход.', 401);
  ok(authPack($u, false));
}

if($action === 'logout'){
  $a = auth();
  if($a) q("DELETE FROM sop_tokens WHERE token=?", [$a['token']]);
  ok();
}

if($action === 'reset_ask'){
  $email = inp('email');
  $t = passResetMake($email);
  if($t !== ''){
    sendMail($email, 'Смена пароля',
      "Кто-то (надеемся, вы) попросил сменить пароль в системе сопровождения.\n\n" .
      "Ссылка действует два часа:\n" . siteUrl() . "?reset=" . $t .
      "\n\nЕсли это не вы — просто не открывайте её.");
  }
  ok(['msg' => 'Если такая почта есть, письмо уже в пути.']);
}

if($action === 'reset_do'){
  $u = passResetUser(inp('reset'));
  if(!$u) fail('Ссылка устарела. Запросите смену пароля заново.');
  $pass = (string)inp('pass');
  if(mb_strlen($pass) < 6) fail('Пароль — минимум шесть символов.');
  q("UPDATE sop_users SET pass=?, reset_token=NULL, reset_at=NULL WHERE id=?",
    [password_hash($pass, PASSWORD_DEFAULT), $u['id']]);
  if($u['status'] !== 'active') ok(['status' => $u['status'], 'msg' => 'Пароль сменили. Доступ ещё не подтверждён.']);
  ok(authPack($u));
}

/* ═══════════ КЛИЕНТКА ═══════════ */
/* Её кабинет: ближайший созвон, история, свободное время для записи. */
if($action === 'my_view'){
  $c = clientOfRequest();
  if(!$c) fail('Нужен вход.', 401);
  $b = upcomingBooking($c['id']);
  $hist = all("SELECT b.*, u.name manager_name FROM sop_bookings b
               JOIN sop_users u ON u.id=b.manager_id
               WHERE b.client_id=? ORDER BY b.dt DESC LIMIT 50", [$c['id']]);
  $list = [];
  foreach($hist as $x){
    $list[] = ['dt' => $x['dt'], 'status' => $x['status'], 'code' => $x['code'],
               'manager' => $x['manager_name'], 'link' => $x['link'],
               'pub' => $x['status'] === 'held' ? (string)($x['rep_pub'] ?? '') : ''];
  }
  ok([
    'name'    => $c['name'],
    'email'   => $c['email'],
    'phone'   => $c['phone'],
    'tg'      => $c['tg'],
    'photo'   => ($c['photo'] ?? '') !== '' ? 'foto/' . $c['photo'] : '',
    'course'  => $c['course'],
    'status'  => $c['status'],
    'held'    => (int)$c['held_manual'] +
                 (int)one("SELECT COUNT(*) c FROM sop_bookings WHERE client_id=? AND status='held'", [$c['id']])['c'],
    'target'  => max(1, (int)cfg('target_calls')),
    'refused' => (int)$c['refused'],
    'certNote'=> $c['status'] === 'done' ? cfg('cert_note') : '',
    'booking' => $b ? ['dt' => $b['dt'], 'manager' => $b['manager_name'], 'link' => $b['link'], 'code' => $b['code']] : null,
    'bookings'=> $list,
    'times'   => freeTimes(30, ($recM = managerByRecKey(inp('rec'))) ? $recM['tg_key'] : ''),
    'recManager' => $recM ? $recM['name'] : null,
    'botLink' => botLink('c_' . $c['tg_key']),
    'tgOn'    => (bool)$c['tg_chat'],
    'hasAccount' => (bool)auth(),
  ]);
}

/* Профиль ученицы: имя и связь правит сама, почта — это логин, курс — из школы. */
if($action === 'client_profile_save'){
  $c = clientOfRequest();
  if(!$c) fail('Нужен вход.', 401);
  $name = trim((string)inp('name'));
  if(mb_strlen($name) < 2) fail('Впишите имя.');
  $tel = phoneOf(inp('phone'));
  if(trim((string)inp('phone')) !== '' && $tel === '') fail('Не разобрали телефон. Формат: +7 999 123-45-67');
  $tg = ltrim(mb_substr(trim((string)inp('tg')), 0, 60), '@');
  q("UPDATE sop_clients SET name=?, phone=?, tg=? WHERE id=?",
    [mb_substr($name, 0, 110), $tel, $tg, $c['id']]);
  /* то же — в аккаунт, чтобы вход и карточка не разъезжались */
  $a = auth();
  if($a && $a['user_id']){
    $u = userById($a['user_id']);
    if($u && $u['role'] === 'client'){
      q("UPDATE sop_users SET name=?, phone=?, tg=? WHERE id=?", [mb_substr($name, 0, 110), $tel, $tg, $u['id']]);
      $pass = (string)inp('pass');
      if($pass !== ''){
        if(mb_strlen($pass) < 6) fail('Новый пароль — минимум шесть символов.');
        q("UPDATE sop_users SET pass=? WHERE id=?", [password_hash($pass, PASSWORD_DEFAULT), $u['id']]);
      }
    }
  }
  ok();
}

/* Фото профиля — как в школе: до трёх мегабайт, JPG/PNG/WEBP, папку foto
   выкладка не трогает. */
if($action === 'client_photo'){
  $c = clientOfRequest();
  if(!$c) fail('Нужен вход.', 401);
  if(empty($_FILES['photo']['tmp_name'])) fail('Файл не долетел. Попробуйте ещё раз.');
  $f = $_FILES['photo'];
  if($f['size'] > 3*1024*1024) fail('Файл больше трёх мегабайт — выберите поменьше.');
  $info = @getimagesize($f['tmp_name']);
  if(!$info) fail('Это не похоже на картинку.');
  $ext = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'][$info['mime']] ?? '';
  if($ext === '') fail('Подойдёт JPG, PNG или WEBP.');
  $dir = __DIR__ . '/foto';
  if(!is_dir($dir)) @mkdir($dir, 0755, true);
  $nameF = 'c' . $c['id'] . '_' . substr(bin2hex(random_bytes(6)), 0, 10) . '.' . $ext;
  if(!@move_uploaded_file($f['tmp_name'], $dir . '/' . $nameF)) fail('Не получилось сохранить файл.');
  if(($c['photo'] ?? '') !== '') @unlink($dir . '/' . $c['photo']);
  q("UPDATE sop_clients SET photo=? WHERE id=?", [$nameF, $c['id']]);
  ok(['photo' => 'foto/' . $nameF]);
}

if($action === 'client_photo_del'){
  $c = clientOfRequest();
  if(!$c) fail('Нужен вход.', 401);
  if(($c['photo'] ?? '') !== '') @unlink(__DIR__ . '/foto/' . $c['photo']);
  q("UPDATE sop_clients SET photo='' WHERE id=?", [$c['id']]);
  ok();
}

if($action === 'book'){
  $c = clientOfRequest();
  if(!$c) fail('Нужен вход.', 401);
  $slot = one("SELECT * FROM sop_slots WHERE id=?", [(int)inp('slot_id')]);
  if(!$slot || $slot['booking_id'] !== null) fail('Это время уже заняли. Выберите другое.');
  if(ts($slot['dt']) < time()) fail('Это время уже прошло. Выберите другое.');
  if($c['status'] === 'done') fail('Ваш онбординг уже завершён. Если нужен ещё созвон — напишите менеджеру.');
  if($c['status'] === 'refund') fail('Запись сейчас закрыта. Если это ошибка — напишите менеджеру.');
  $have = upcomingBooking($c['id']);
  if($have) fail('У вас уже есть запись: ' . fmtRu($have['dt']) . '. Сначала перенесите или отмените её.');
  $b = bookSlot($slot, $c);
  if(!$b) fail('Это время только что заняли. Выберите другое.');
  if((int)$c['refused'] === 1){
    q("UPDATE sop_clients SET refused=0 WHERE id=?", [$c['id']]);
    histAdd($c['id'], null, 'refuse', 'Передумала: записалась сама после отказа от созвонов');
  }
  $m = userById($b['manager_id']);
  ok(['booking' => [
    'dt' => $b['dt'], 'link' => $b['link'], 'code' => $b['code'],
    'manager' => $m ? $m['name'] : '—',
  ]]);
}

/* Запись по коду: просмотр, перенос, отмена — этой же страницей пользуются
   ссылки из писем и кнопка «Перенести или отменить» в кабинете. */
if($action === 'booking'){
  $b = bookingByCode(inp('code'));
  if(!$b) fail('Запись не нашлась. Проверьте код — пять символов из подтверждения.', 404);
  $m = userById($b['manager_id']);
  $c = clientById($b['client_id']);
  ok(['booking' => [
    'dt' => $b['dt'], 'link' => $b['link'], 'code' => $b['code'], 'status' => $b['status'],
    'manager' => $m ? $m['name'] : '—',
    'duration' => (int)cfg('duration'),
    'times' => freeTimes(),
    'tg' => $c ? botLink('c_' . $c['tg_key']) : '',
    'tgOn' => (bool)($c['tg_chat'] ?? null),
  ]]);
}

if($action === 'code_move'){
  $b = bookingByCode(inp('code'));
  if(!$b) fail('Запись не нашлась.', 404);
  $slotId = (int)inp('slot_id');
  if(!$slotId) fail('Выберите время.');
  $r = moveBooking($b, $slotId, 'ученица');
  if($r === 'inactive') fail('Эта запись уже не активна — запишитесь заново из кабинета.');
  if($r === 'same')     fail('Это то же самое время.');
  if($r === 'taken')    fail('Это время только что заняли. Выберите другое — ваше прежнее место осталось за вами.');
  ok(['booking' => ['dt' => $r['dt'], 'link' => $r['link'], 'code' => $r['code'], 'status' => $r['status']]]);
}

if($action === 'code_cancel'){
  $b = bookingByCode(inp('code'));
  if(!$b) fail('Запись не нашлась.', 404);
  if(!cancelBooking($b, 'ученица')) fail('Эта запись уже не активна.');
  ok();
}

/* ═══════════ КАБИНЕТ МЕНЕДЖЕРА (и владельца) ═══════════ */
if($action === 'mgr_view'){
  $u = requireRole('staff');
  /* Созвоны: будущие все, прошлые за 60 дней. Менеджеры видят всех — так
     договорились: любой свободный может подхватить ученицу. */
  /* Заодно тянем, какой это созвон по счёту у ученицы и её пару — менеджеру
     перед звонком важно знать, первая это встреча или четвёртая. */
  $rows = all("SELECT b.*, c.name client_name, c.course, c.phone c_phone, c.tg c_tg, c.email c_email,
                      c.noshow_cnt, c.moved_cnt, c.pair_name, u.name manager_name,
                      c.held_manual + (SELECT COUNT(*) FROM sop_bookings h
                        WHERE h.client_id=b.client_id AND h.status='held' AND h.dt < b.dt) call_no,
                      (SELECT h.rep_next FROM sop_bookings h
                        WHERE h.client_id=b.client_id AND h.status='held' AND h.dt < b.dt
                          AND h.rep_next<>'' ORDER BY h.dt DESC LIMIT 1) prev_next,
                      (SELECT h.rep_next_dt FROM sop_bookings h
                        WHERE h.client_id=b.client_id AND h.status='held' AND h.dt < b.dt
                          AND h.rep_next<>'' ORDER BY h.dt DESC LIMIT 1) prev_next_dt
               FROM sop_bookings b
               JOIN sop_clients c ON c.id=b.client_id
               JOIN sop_users u   ON u.id=b.manager_id
               WHERE b.dt > ? ORDER BY b.dt",
              [date('Y-m-d H:i:s', time() - 60*86400)]);
  $slots = all("SELECT * FROM sop_slots WHERE manager_id=? AND dt > ? ORDER BY dt", [$u['id'], now()]);
  ok(['bookings' => $rows, 'slots' => $slots, 'meId' => (int)$u['id'],
      'target' => max(1, (int)cfg('target_calls')),
      'myPair' => (string)($u['pair_name'] ?? ''),
      'botLink' => botLink('m_' . $u['tg_key']), 'tgOn' => (bool)$u['tg_chat']]);
}

if($action === 'slots_add'){
  $u = requireRole('manager');
  $date  = inp('date');
  $times = inp('times', []);
  $linkRaw = (string)inp('link');
  $link  = normUrl(mb_substr($linkRaw, 0, 290));
  $mistake = urlMistake($linkRaw, $link);
  if($mistake !== '') fail($mistake);
  if(!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) fail('Выберите дату.');
  if(!is_array($times) || !$times) fail('Отметьте хотя бы одно время.');
  $added = 0;
  foreach($times as $tm){
    if(!preg_match('/^\d{2}:\d{2}$/', (string)$tm)) continue;
    $dt = $date . ' ' . $tm . ':00';
    if(ts($dt) < time()) continue;
    try{
      q("INSERT INTO sop_slots(manager_id,dt,link,created_at) VALUES(?,?,?,?)", [$u['id'], $dt, $link, now()]);
      $added++;
    }catch(Throwable $e){ /* такой слот уже есть — не ошибка */ }
  }
  ok(['added' => $added]);
}

if($action === 'slot_del'){
  $u = requireRole('manager');
  $s = one("SELECT * FROM sop_slots WHERE id=? AND manager_id=?", [(int)inp('id'), $u['id']]);
  if(!$s) fail('Слот не нашёлся.');
  if($s['booking_id'] !== null) fail('На это время уже записана ученица. Сначала попросите её перенести созвон.');
  q("DELETE FROM sop_slots WHERE id=?", [$s['id']]);
  ok();
}

if($action === 'slot_link'){
  $u = requireRole('manager');
  $s = one("SELECT * FROM sop_slots WHERE id=? AND manager_id=?", [(int)inp('id'), $u['id']]);
  if(!$s) fail('Слот не нашёлся.');
  $linkRaw = (string)inp('link');
  $link = normUrl(mb_substr($linkRaw, 0, 290));
  $mistake = urlMistake($linkRaw, $link);
  if($mistake !== '') fail($mistake);
  q("UPDATE sop_slots SET link=? WHERE id=?", [$link, $s['id']]);
  /* если на слот уже записались — ссылка должна доехать и до записи, и до ученицы */
  if($s['booking_id']){
    $b = bookingById($s['booking_id']);
    if($b && $b['status'] === 'booked' && $link !== '' && $link !== $b['link']){
      q("UPDATE sop_bookings SET link=? WHERE id=?", [$link, $b['id']]);
      $c = clientById($b['client_id']);
      if($c) tell($c['tg_chat'], $c['email'], 'Ссылка на созвон',
        "Ссылка на ваш созвон " . fmtRu($b['dt']) . ":\n" . $link);
    }
  }
  ok();
}

/* Менеджер отмечает исход созвона из кабинета */
if($action === 'mark'){
  $u = requireRole('staff');
  $b = bookingById((int)inp('id'));
  if(!$b) fail('Запись не нашлась.');
  $what = inp('what');
  if($what === 'held'   && !markHeld($b, $u['name']))   fail('Эту запись уже нельзя отметить проведённой.');
  if($what === 'noshow' && !markNoshow($b, $u['name'])) fail('Эту запись уже нельзя отметить неявкой.');
  if($what === 'move'){
    /* Время за ученицу не выбираем — зовём её перенести самой.
       Место остаётся за ней, пока не выберет новое. */
    if($b['status'] !== 'booked') fail('Эта запись уже не активна.');
    $c = clientById($b['client_id']);
    if(!$c) fail('Ученица не нашлась.');
    tell($c['tg_chat'], $c['email'], 'Просим перенести созвон',
      "Здравствуйте, " . $c['name'] . "!\nМенеджер просит перенести созвон " . fmtRu($b['dt']) . " на другое время.\n" .
      "Выберите новое здесь — прежнее место остаётся за вами, пока не выберете:\n" . codeUrl($b));
    histAdd($b['client_id'], $b['id'], 'moveask', $u['name'] . ' попросил(а) перенести созвон ' . fmtRu($b['dt']));
  }
  if($what === 'cancel' && !cancelBooking($b, $u['name'] . ' (менеджер)')) fail('Эта запись уже не активна.');
  if(!in_array($what, ['held','noshow','move','cancel'], true)) fail('Непонятная отметка.');
  ok(['booking' => bookingById($b['id'])]);
}

/* Менеджер (любой, не только куратор) переставляет ученицу сам — на своё
   свободное время, на время коллеги, или заводит время под неё прямо
   сейчас, если подходящего слота ещё нет. В отличие от «Просить перенести»
   решение принимает менеджер, а не ждёт ответа от ученицы. */
if($action === 'move_times'){
  requireRole('staff');
  ok(['times' => freeTimes(60)]);
}

if($action === 'staff_managers'){
  requireRole('staff');
  ok(['managers' => all("SELECT id, name FROM sop_users WHERE role IN ('manager','owner') AND status='active' ORDER BY name")]);
}

if($action === 'staff_reschedule'){
  $u = requireRole('staff');
  $b = bookingById((int)inp('id'));
  if(!$b) fail('Запись не нашлась.');
  if($b['status'] !== 'booked') fail('Эта запись уже не активна.');
  $slotId = (int)inp('slot_id');
  $slot = null;
  if($slotId){
    $slot = one("SELECT * FROM sop_slots WHERE id=?", [$slotId]);
    if(!$slot) fail('Это время только что заняли. Выберите другое.');
  }else{
    /* своё время под ученицу: дата+час, которых ещё нет в расписании */
    $dt = trim((string)inp('dt'));
    if(!preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}$/', $dt)) fail('Укажите дату и время.');
    $mgrId = (int)inp('manager_id') ?: (int)$u['id'];
    $mgr = userById($mgrId);
    if(!$mgr || !in_array($mgr['role'], ['manager', 'owner'], true) || $mgr['status'] !== 'active')
      fail('Менеджер не нашёлся.');
    $dtFull = $dt . ':00';
    if(ts($dtFull) < time()) fail('Это время уже прошло.');
    try{ q("INSERT INTO sop_slots(manager_id,dt,link,created_at) VALUES(?,?,?,?)", [$mgrId, $dtFull, '', now()]); }
    catch(Throwable $e){ /* слот на это время уже есть — возьмём его */ }
    $slot = one("SELECT * FROM sop_slots WHERE manager_id=? AND dt=?", [$mgrId, $dtFull]);
    if(!$slot) fail('Не получилось завести время.');
  }
  $r = moveBooking($b, $slot['id'], $u['name'] . ' (менеджер)');
  if($r === 'inactive') fail('Эта запись уже не активна.');
  if($r === 'same')     fail('Это то же самое время.');
  if($r === 'taken')    fail('Это время только что заняли. Выберите другое.');
  ok(['booking' => $r]);
}

if($action === 'report_save'){
  $u = requireRole('staff');
  $b = bookingById((int)inp('id'));
  if(!$b) fail('Запись не нашлась.');
  if($b['status'] !== 'held') fail('Отчёт пишется по проведённому созвону — сначала отметьте исход.');
  $note = (string)inp('note');
  if(mb_strlen(trim($note)) < 3) fail('Напишите отчёт — хотя бы пару предложений.');
  $nextDt = inp('next_dt');
  if($nextDt !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $nextDt)) fail('Не разобрали дату следующего шага.');
  saveReport($b, $note, inp('status'), inp('next'), $nextDt, $u['name'], inp('pub'));
  ok(['booking' => bookingById($b['id'])]);
}

/* Менеджер записывает ученицу сам — договорились голосом, время внесено. */
if($action === 'book_client'){
  $u = requireRole('manager');
  $c = clientById((int)inp('client_id'));
  if(!$c) fail('Ученица не нашлась.');
  if(upcomingBooking($c['id'])) fail('У неё уже есть запись. Сначала перенесите или отмените её.');
  $s = one("SELECT * FROM sop_slots WHERE id=? AND manager_id=?", [(int)inp('slot_id'), $u['id']]);
  if(!$s) fail('Это не ваш слот.');
  if($s['booking_id'] !== null || ts($s['dt']) < time()) fail('Слот уже занят или прошёл.');
  $b = bookSlot($s, $c);
  if(!$b) fail('Слот только что заняли.');
  ok(['booking' => $b]);
}

if($action === 'clients'){
  requireRole('staff');
  $rows = all("SELECT c.*,
      (SELECT MIN(b.dt) FROM sop_bookings b WHERE b.client_id=c.id AND b.status='booked' AND b.dt > ?) next_dt,
      (SELECT MAX(b.dt) FROM sop_bookings b WHERE b.client_id=c.id AND b.status='held') last_held,
      (SELECT COUNT(*)  FROM sop_bookings b WHERE b.client_id=c.id AND b.status='held') held_cnt
    FROM sop_clients c ORDER BY c.name", [now()]);
  foreach($rows as &$r){
    $r['held_cnt'] = (int)$r['held_cnt'] + (int)$r['held_manual'];
    $r['stage'] = stageOf($r, (int)$r['held_cnt']);
    $r['missing'] = clientMissingData($r);
    unset($r['kkey'], $r['tg_key'], $r['tg_chat']);
  }
  ok(['clients' => $rows, 'statuses' => CLIENT_STATUSES(), 'pairs' => pairsList()]);
}

if($action === 'client_card'){
  requireRole('staff');
  $c = clientById((int)inp('id'));
  if(!$c) fail('Ученица не нашлась.');
  $bookings = all("SELECT b.*, u.name manager_name FROM sop_bookings b
                   JOIN sop_users u ON u.id=b.manager_id
                   WHERE b.client_id=? ORDER BY b.dt DESC", [$c['id']]);
  $hist = all("SELECT * FROM sop_history WHERE client_id=? ORDER BY at DESC, id DESC LIMIT 200", [$c['id']]);
  $held = (int)$c['held_manual'];
  foreach($bookings as $x) if($x['status'] === 'held') $held++;
  ok([
    'client'   => $c,
    'held'     => $held,
    'stage'    => stageOf($c, $held),
    'missing'  => clientMissingData($c),
    'url'      => clientUrl($c),
    'botLink'  => botLink('c_' . $c['tg_key']),
    'bookings' => $bookings,
    'history'  => $hist,
    'statuses' => CLIENT_STATUSES(),
    'refundReasons' => REFUND_REASONS(),
    'pairs'    => pairsList(),
  ]);
}

/* Закрепить ученицу за парой менеджеров — куратор делает это вручную;
   после этого её ведут именно эти двое, до выпуска (заказчица процесса, 29.08). */
if($action === 'client_pair'){
  $u = requireRole('staff');
  $c = clientById((int)inp('id'));
  if(!$c) fail('Ученица не нашлась.');
  $pair = mb_substr(trim((string)inp('pair')), 0, 60);
  if($pair !== '' && !in_array($pair, pairsList(), true)) fail('Такой пары нет — сначала объедините менеджеров в паре в разделе «Менеджеры».');
  q("UPDATE sop_clients SET pair_name=? WHERE id=?", [$pair, $c['id']]);
  histAdd($c['id'], null, 'pair', $pair !== '' ? 'Закреплена за парой «' . $pair . '» — ' . $u['name'] : 'Снята с закрепления за парой — ' . $u['name']);
  ok();
}

if($action === 'client_done'){
  $u = requireRole('staff');
  $c = clientById((int)inp('id'));
  if(!$c) fail('Ученица не нашлась.');
  if($c['status'] === 'done') fail('Онбординг уже отмечен завершённым.');
  if(upcomingBooking($c['id'])) fail('У неё ещё есть запись на созвон. Сначала проведите или отмените его.');
  q("UPDATE sop_clients SET status='done', done_at=?, done_by=? WHERE id=?", [now(), $u['name'], $c['id']]);
  histAdd($c['id'], null, 'done', 'Онбординг завершён — ' . $u['name']);
  adminNotify("🎓 Онбординг завершён: " . $c['name'] . " (" . $u['name'] . ")");
  /* сертификат и подарок — сразу ученице, текст правится в Настройках */
  tell($c['tg_chat'], $c['email'], 'Онбординг завершён 🎉',
    "Здравствуйте, " . $c['name'] . "!\n\n" . cfg('cert_note') .
    "\n\nВаш кабинет: " . clientUrl($c));
  ok();
}

if($action === 'client_work'){
  $u = requireRole('staff');
  $c = clientById((int)inp('id'));
  if(!$c) fail('Ученица не нашлась.');
  q("UPDATE sop_clients SET status='work', done_at=NULL, done_by='' WHERE id=?", [$c['id']]);
  histAdd($c['id'], null, 'work', 'Возвращена в работу — ' . $u['name']);
  ok();
}

/* Поля сопровождения из таблицы: уроки, бонус, ссылки, старт цикла */
if($action === 'client_fields'){
  $u = requireRole('staff');
  $c = clientById((int)inp('id'));
  if(!$c) fail('Ученица не нашлась.');
  $lessons = function($k) { $v = inp($k, ''); return $v === '' ? null : max(0, (int)$v); };
  $start = inp('start_dt', '');
  if($start !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $start)) fail('Не разобрали дату старта.');
  q("UPDATE sop_clients SET bonus=?, omni_url=?, deal_url=?, lessons_start=?, lessons_d5=?, lessons_end=?, start_dt=? WHERE id=?",
    [mb_substr((string)inp('bonus'), 0, 190), mb_substr((string)inp('omni_url'), 0, 290),
     mb_substr((string)inp('deal_url'), 0, 290),
     $lessons('lessons_start'), $lessons('lessons_d5'), $lessons('lessons_end'),
     $start ?: null, $c['id']]);
  ok();
}

/* Возврат и заморозка — статусы из таблицы заказчицы процесса. Возврат закрывает
   запись на созвоны; действующая запись отменяется. */
if($action === 'client_status'){
  $u = requireRole('staff');
  $c = clientById((int)inp('id'));
  if(!$c) fail('Ученица не нашлась.');
  $to = inp('to');
  if(!in_array($to, ['work', 'refund', 'frozen'], true)) fail('Непонятный статус.');
  $reason = '';
  if($to === 'refund'){
    $reason = inp('reason');
    if(!in_array($reason, REFUND_REASONS(), true)) fail('Выберите причину возврата.');
    $b = upcomingBooking($c['id']);
    if($b) cancelBooking($b, $u['name'] . ' (возврат)');
    q("UPDATE sop_clients SET status='refund', refund_reason=?, refund_at=? WHERE id=?", [$reason, now(), $c['id']]);
    adminNotify("↩️ Возврат: " . $c['name'] . " — " . $reason . " (" . $u['name'] . ")");
  }else{
    q("UPDATE sop_clients SET status=?, refund_reason='', refund_at=NULL WHERE id=?", [$to, $c['id']]);
  }
  histAdd($c['id'], null, 'status',
    ['work' => 'Возвращена в работу', 'refund' => 'Возврат: ' . $reason, 'frozen' => 'Заморозка / старт позже'][$to] . ' — ' . $u['name']);
  ok();
}

/* Отказ от созвонов: сама не хочет — помним, не шлём приглашения,
   сертификата не лишаем (решение заказчицы процесса). */
if($action === 'client_refuse'){
  $u = requireRole('staff');
  $c = clientById((int)inp('id'));
  if(!$c) fail('Ученица не нашлась.');
  $to = (int)inp('to', 1) ? 1 : 0;
  q("UPDATE sop_clients SET refused=? WHERE id=?", [$to, $c['id']]);
  histAdd($c['id'], null, 'refuse', ($to ? 'Отказалась от созвонов' : 'Вернулась к созвонам') . ' — отметил(а) ' . $u['name']);
  ok();
}

if($action === 'client_note'){
  requireRole('staff');
  $c = clientById((int)inp('id'));
  if(!$c) fail('Ученица не нашлась.');
  q("UPDATE sop_clients SET note=? WHERE id=?", [mb_substr((string)inp('note'), 0, 390), $c['id']]);
  ok();
}

/* Отправить ученице письмо с личной ссылкой на запись */
if($action === 'client_invite'){
  $u = requireRole('staff');
  $c = clientById((int)inp('id'));
  if(!$c) fail('Ученица не нашлась.');
  if($c['email'] === '') fail('У неё нет почты в карточке — отправьте ссылку сами в мессенджере.');
  $sent = sendMail($c['email'], 'Созвон с менеджером сопровождения',
    "Здравствуйте, " . $c['name'] . "!\n\n" .
    "Поздравляем с покупкой курса! Чтобы вход в профессию прошёл легче, менеджер сопровождения " .
    "созвонится с вами и поможет составить план.\n\n" .
    "Выберите удобное время здесь:\n" . clientUrl($c) .
    "\n\nСсылка личная и постоянная — сохраните письмо.");
  if(!$sent) fail('Письмо не ушло — попробуйте позже.');
  histAdd($c['id'], null, 'invite', 'Отправлено приглашение на почту — ' . $u['name']);
  ok();
}

/* Профиль: имя, контакты, постоянная ссылка на комнату, смена пароля */
if($action === 'profile_save'){
  $a = auth();
  $u = $a && $a['user_id'] ? userById($a['user_id']) : null;
  if(!$u || $u['status'] !== 'active') fail('Нужен вход.', 401);
  $name = trim((string)inp('name'));
  if(mb_strlen($name) < 2) fail('Впишите имя.');
  $tel = phoneOf(inp('phone'));
  if(trim((string)inp('phone')) !== '' && $tel === '') fail('Не разобрали телефон.');
  $linkRaw = (string)inp('link');
  $linkNorm = normUrl(mb_substr($linkRaw, 0, 290));
  $mistake = urlMistake($linkRaw, $linkNorm);
  if($mistake !== '') fail($mistake);
  q("UPDATE sop_users SET name=?, phone=?, tg=?, link=? WHERE id=?",
    [mb_substr($name, 0, 110), $tel, ltrim(mb_substr((string)inp('tg'), 0, 60), '@'),
     $linkNorm, $u['id']]);
  $pass = (string)inp('pass');
  if($pass !== ''){
    if(mb_strlen($pass) < 6) fail('Новый пароль — минимум шесть символов.');
    q("UPDATE sop_users SET pass=? WHERE id=?", [password_hash($pass, PASSWORD_DEFAULT), $u['id']]);
  }
  ok(authPack(userById($u['id']), false));
}

/* Менеджеры глазами куратора: кто сколько времени открыл, кто к кому
   записан, у кого долг по отчётам. Только смотреть — доступы правит владелец. */
if($action === 'managers_view'){
  requireRole('admin');
  $mans = all("SELECT id, name, pair_name, tg_chat, link FROM sop_users
               WHERE role IN ('manager','owner') AND status='active' ORDER BY name");
  $out = [];
  foreach($mans as $m){
    $slots = all("SELECT s.id, s.dt, s.booking_id, s.link,
                    b.status b_status, c.name client_name
                  FROM sop_slots s
                  LEFT JOIN sop_bookings b ON b.id = s.booking_id
                  LEFT JOIN sop_clients c ON c.id = b.client_id
                  WHERE s.manager_id=? AND s.dt > ? ORDER BY s.dt",
                 [$m['id'], date('Y-m-d H:i:s', time() - 12*3600)]);
    $free = 0; $busy = 0;
    foreach($slots as $s){ if($s['booking_id'] === null) $free++; else $busy++; }
    $out[] = [
      'id' => (int)$m['id'], 'name' => $m['name'],
      'pair' => (string)$m['pair_name'], 'tgOn' => (bool)$m['tg_chat'],
      'hasLink' => trim((string)$m['link']) !== '',
      'free' => $free, 'busy' => $busy,
      'held'   => (int)one("SELECT COUNT(*) c FROM sop_bookings WHERE manager_id=? AND status='held'", [$m['id']])['c'],
      'noshow' => (int)one("SELECT COUNT(*) c FROM sop_bookings WHERE manager_id=? AND status='noshow'", [$m['id']])['c'],
      'debt'   => (int)one("SELECT COUNT(*) c FROM sop_bookings WHERE manager_id=? AND status='held' AND rep_at IS NULL", [$m['id']])['c'],
      'slots'  => $slots,
    ];
  }
  ok(['managers' => $out]);
}

/* ═══════════ ВЛАДЕЛЕЦ ═══════════ */
if($action === 'users'){
  requireRole('owner');
  $rows = all("SELECT id, email, name, role, status, phone, tg, tg_chat, pair_name, created_at, last_at
               FROM sop_users ORDER BY status='pending' DESC, name");
  ok(['users' => $rows]);
}

/* Пара менеджеров (график 2/2, с 26.08): одинаковая метка у двоих
   объединяет их — куда бы дальше ни закрепляли ученицу. */
if($action === 'manager_pair'){
  requireRole('owner');
  $u = userById((int)inp('id'));
  if(!$u || $u['role'] !== 'manager') fail('Это не менеджер.');
  q("UPDATE sop_users SET pair_name=? WHERE id=?", [mb_substr(trim((string)inp('pair')), 0, 60), $u['id']]);
  ok();
}

if($action === 'user_set'){
  $me = requireRole('owner');
  $u = userById((int)inp('id'));
  if(!$u) fail('Аккаунт не нашёлся.');
  $what = inp('what');
  if($what === 'approve'){
    q("UPDATE sop_users SET status='active' WHERE id=?", [$u['id']]);
    sendMail($u['email'], 'Доступ открыт',
      "Здравствуйте, " . $u['name'] . "!\nВладелец подтвердил ваш доступ менеджера сопровождения.\nВходите: " . siteUrl());
  }elseif($what === 'reject'){
    if((int)$u['id'] === (int)$me['id']) fail('Себя закрыть нельзя.');
    q("UPDATE sop_users SET status='rejected' WHERE id=?", [$u['id']]);
    q("DELETE FROM sop_tokens WHERE user_id=?", [$u['id']]);
  }elseif(in_array($what, ['owner', 'admin', 'manager'], true)){
    /* Смена роли: человек ошибся при регистрации или вырос — владелец
       поправляет в один клик, без пересоздания аккаунта. */
    if((int)$u['id'] === (int)$me['id'] && $what !== 'owner')
      fail('Себя разжаловать нельзя — иначе владельцев может не остаться.');
    q("UPDATE sop_users SET role=? WHERE id=?", [$what, $u['id']]);
    /* куратор и владелец получают служебные уведомления, менеджер — свои */
    if($u['tg_chat']) tgBind($u['tg_chat'], $what === 'manager' ? 'manager' : 'admin', $u['id']);
  }else fail('Непонятное действие.');
  ok();
}

if($action === 'import_now'){
  requireRole('admin');
  ok(['result' => importClients()]);
}

if($action === 'stats'){
  requireRole('admin');
  $from = inp('from') ?: date('Y-m-d', time() - 30*86400);
  $to   = inp('to')   ?: date('Y-m-d');
  if(!preg_match('/^\d{4}-\d{2}-\d{2}$/', $from) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)) fail('Не разобрали период.');
  $f = $from . ' 00:00:00'; $t = $to . ' 23:59:59';

  $byStatus = [];
  foreach(all("SELECT status, COUNT(*) c FROM sop_clients GROUP BY status") as $r) $byStatus[$r['status']] = (int)$r['c'];
  /* верхняя строка СВОДНОЙ: всего · в работе · возвраты · осталось */
  $total = array_sum($byStatus);
  /* за период: возвраты и завершения (у перенесённых из таблицы дат нет —
     они видны только в общих числах) */
  $refundInPeriod = (int)one("SELECT COUNT(*) c FROM sop_clients WHERE status='refund' AND refund_at BETWEEN ? AND ?", [$f, $t])['c'];
  $doneInPeriod   = (int)one("SELECT COUNT(*) c FROM sop_clients WHERE done_at BETWEEN ? AND ?", [$f, $t])['c'];
  /* уроки: сумма последних известных значений по ученицам */
  $lessonsViewed = (int)one("SELECT COALESCE(SUM(COALESCE(lessons_end, lessons_d5, lessons_start)),0) s FROM sop_clients", [])['s'];
  /* касания по номерам: у скольких учениц проведено ≥N созвонов
     (системные + перенесённые из таблицы) */
  $target = max(1, (int)cfg('target_calls'));
  $touches = [];
  $heldRows = all("SELECT c.held_manual + (SELECT COUNT(*) FROM sop_bookings b
                     WHERE b.client_id=c.id AND b.status='held') h
                   FROM sop_clients c WHERE c.status <> 'refund'");
  for($n = 1; $n <= $target; $n++){
    $cnt = 0;
    foreach($heldRows as $hr) if((int)$hr['h'] >= $n) $cnt++;
    $touches[$n] = $cnt;
  }
  /* Исходы считаем за период, а «впереди» — всегда целиком: будущая запись
     не должна пропадать из таблицы из-за границ периода. */
  $byManager = all("SELECT u.name,
      SUM(b.status='held'   AND b.dt BETWEEN ? AND ?) held,
      SUM(b.status='noshow' AND b.dt BETWEEN ? AND ?) noshow,
      SUM(b.status='booked' AND b.dt > NOW()) planned
    FROM sop_bookings b JOIN sop_users u ON u.id=b.manager_id
    GROUP BY b.manager_id, u.name ORDER BY held DESC", [$f, $t, $f, $t]);
  /* Переносы запись переживает (остаётся той же), поэтому считаем их по ленте */
  $moved = (int)one("SELECT COUNT(*) c FROM sop_history WHERE kind='moved' AND at BETWEEN ? AND ?", [$f, $t])['c'];
  /* сколько дней проходит от появления в списке до первого проведённого созвона */
  $speed = one("SELECT AVG(DATEDIFF(x.first_held, c.created_at)) d, COUNT(*) n
    FROM (SELECT client_id, MIN(dt) first_held FROM sop_bookings WHERE status='held' GROUP BY client_id) x
    JOIN sop_clients c ON c.id=x.client_id
    WHERE x.first_held BETWEEN ? AND ?", [$f, $t]);
  $days = max(1, (int)cfg('stale_days'));
  $stale = (int)one("SELECT COUNT(*) c FROM sop_clients c
    WHERE c.status='new' AND c.refused=0 AND c.created_at < ?
      AND NOT EXISTS (SELECT 1 FROM sop_bookings b WHERE b.client_id=c.id AND b.status='booked' AND b.dt > ?)",
    [date('Y-m-d H:i:s', time() - $days*86400), now()])['c'];
  $refused = (int)one("SELECT COUNT(*) c FROM sop_clients WHERE refused=1", [])['c'];
  /* блоки как в СВОДНОЙ таблице заказчицы процесса */
  $newInPeriod  = (int)one("SELECT COUNT(*) c FROM sop_clients WHERE created_at BETWEEN ? AND ?", [$f, $t])['c'];
  $heldInPeriod = (int)one("SELECT COUNT(*) c FROM sop_bookings WHERE status='held' AND dt BETWEEN ? AND ?", [$f, $t])['c'];
  $growth = one("SELECT SUM(lessons_end - lessons_start) g, COUNT(*) n FROM sop_clients
                 WHERE lessons_start IS NOT NULL AND lessons_end IS NOT NULL", []);
  $byCourse = all("SELECT course, COUNT(*) c FROM sop_clients WHERE course<>'' GROUP BY course ORDER BY c DESC LIMIT 10");
  $byReason = all("SELECT refund_reason reason, COUNT(*) c FROM sop_clients WHERE status='refund' GROUP BY refund_reason ORDER BY c DESC");
  $stages = [];
  $cliAll = all("SELECT c.*, (SELECT COUNT(*) FROM sop_bookings b WHERE b.client_id=c.id AND b.status='held') held_cnt
                 FROM sop_clients c WHERE c.status IN ('new','work')");
  foreach($cliAll as $cc){
    $st = stageOf($cc, (int)$cc['held_cnt'] + (int)$cc['held_manual']);
    if(mb_strpos($st, 'Просрочено') === 0) $st = 'Просрочено (требуют действий)';
    elseif(mb_strpos($st, 'В графике') === 0) $st = 'В графике';
    $stages[$st] = ($stages[$st] ?? 0) + 1;
  }
  ok([
    'byStatus'  => $byStatus,
    'byManager' => $byManager,
    'moved'     => $moved,
    /* Отрицательным быть не может: у перенесённых карточек дата появления
       в системе позже самого созвона — показываем 0, а не «-1,5 дня». */
    'avgDays'   => $speed && $speed['d'] !== null ? max(0, round((float)$speed['d'], 1)) : null,
    'firstHeld' => (int)($speed['n'] ?? 0),
    'stale'     => $stale,
    'refused'   => $refused,
    'total'        => $total,
    'refundInPeriod' => $refundInPeriod,
    'doneInPeriod' => $doneInPeriod,
    'lessonsViewed'=> $lessonsViewed,
    'touches'      => $touches,
    'from'         => $from,
    'to'           => $to,
    'newInPeriod'  => $newInPeriod,
    'heldInPeriod' => $heldInPeriod,
    'lessonsGrowth'=> $growth && $growth['g'] !== null ? (int)$growth['g'] : null,
    'byCourse'  => $byCourse,
    'byReason'  => $byReason,
    'stages'    => $stages,
    'staleDays' => $days,
    'statuses'  => CLIENT_STATUSES(),
    'blocksOn'  => cfg('stats_blocks'),
  ]);
}

if($action === 'settings_get'){
  requireRole('owner');
  $s = settings();
  ok(['settings' => [
    'org' => $s['org'], 'duration' => $s['duration'], 'remind24' => $s['remind24'],
    'remind2' => $s['remind2'], 'courses_allow' => $s['courses_allow'],
    'auto_import' => $s['auto_import'], 'import_from' => $s['import_from'], 'stale_days' => $s['stale_days'],
    'alert_email' => $s['alert_email'], 'tz' => $s['tz'],
    'target_calls' => $s['target_calls'], 'cert_note' => $s['cert_note'],
    'last_import' => $s['last_import'] ?? '',
    'stats_blocks' => $s['stats_blocks'],
  ], 'statsBlocks' => STATS_BLOCKS()]);
}

if($action === 'settings_set'){
  requireRole('owner');
  foreach(['org','duration','remind24','remind2','courses_allow','auto_import','import_from','stale_days','alert_email','tz','target_calls','cert_note'] as $k){
    $v = inp($k, null);
    if($v !== null) setSetting($k, is_string($v) ? $v : (string)$v);
  }
  ok();
}

/* Владелец сам решает, какие блоки видны в Статистике — переключатели,
   без права заводить произвольные новые метрики (заказчица процесса, 29.08). */
if($action === 'stats_blocks_set'){
  requireRole('owner');
  $blocks = inp('blocks', []);
  if(!is_array($blocks)) fail('Не разобрали список блоков.');
  $known = array_keys(STATS_BLOCKS());
  $blocks = array_values(array_intersect($known, $blocks));
  /* всё выбрано — храним как «пусто» (по умолчанию показываем все) */
  setSetting('stats_blocks', count($blocks) === count($known) ? '' : implode(',', $blocks));
  ok();
}

if($action === 'tg_page'){
  $me = requireRole('owner');
  $chats = all("SELECT t.chat, t.role, t.uname, t.fname, t.updated_at,
                       u.name user_name, c.name client_name
                FROM sop_tg t
                LEFT JOIN sop_users u ON u.id=t.user_id
                LEFT JOIN sop_clients c ON c.id=t.client_id
                ORDER BY t.updated_at DESC LIMIT 100");
  ok([
    'relayKey'  => cfg('relay_key'),
    'tokenSet'  => cfg('tg_token') !== '',
    'bot'       => cfg('bot_user'),
    'relayAt'   => cfg('last_relay'),
    'relayLive' => tgAlive(),
    'myLink'    => botLink('m_' . $me['tg_key']),
    'chats'     => $chats,
    'queue'     => (int)one("SELECT COUNT(*) c FROM sop_outbox WHERE status='pending'")['c'],
  ]);
}

if($action === 'tg_token'){
  requireRole('owner');
  /* Поле нарочно называется bot_token: под именем «token» приложение шлёт
     ключ входа в кабинет, и токен бота однажды затёрся им — час отладки. */
  /* При копировании из Telegram в строку часто заезжают пробелы и переносы —
     вычищаем их сами, а не заставляем человека угадывать, что не так. */
  $t = preg_replace('/\s+/u', '', (string)inp('bot_token'));
  if($t !== '' && !preg_match('/^\d+:[\w-]+$/', $t)){
    logit('tg', 'токен отклонён: длина ' . mb_strlen($t) . ', начало «' . mb_substr($t, 0, 4) . '…»');
    fail('Токен выглядит не так: должен быть вида 1234567890:AA… Скопируйте его у BotFather целиком, одной строкой.');
  }
  setSetting('tg_token', $t);
  if($t === '') setSetting('bot_user', '');
  logit('tg', $t === '' ? 'токен стёрт владельцем' : 'токен сохранён, длина ' . strlen($t));
  ok();
}

if($action === 'tg_unbind'){
  requireRole('owner');
  $chat = (int)inp('chat');
  q("DELETE FROM sop_tg WHERE chat=?", [$chat]);
  q("UPDATE sop_users SET tg_chat=NULL WHERE tg_chat=?", [$chat]);
  q("UPDATE sop_clients SET tg_chat=NULL WHERE tg_chat=?", [$chat]);
  ok();
}

if($action === 'log_view'){
  requireRole('owner');
  ok(['rows' => all("SELECT * FROM sop_log ORDER BY id DESC LIMIT 200")]);
}

/* ═══════════ РЕЛЕ ═══════════ */
if($action === 'relay'){
  $key = inp('key');
  if($key === '' || !hash_equals((string)cfg('relay_key'), $key)) fail('Нет доступа.', 403);
  setSetting('last_relay', now());

  foreach((array)inp('ack', []) as $id) q("UPDATE sop_outbox SET status='sent', sent_at=? WHERE id=?", [now(), (int)$id]);
  foreach((array)inp('failed', []) as $f){
    q("UPDATE sop_outbox SET tries=tries+1, err=?, status=IF(tries>=4,'failed','pending') WHERE id=?",
      [mb_substr((string)($f['err'] ?? 'error'), 0, 280), (int)($f['id'] ?? 0)]);
  }
  foreach((array)inp('updates', []) as $u){
    try{ handleUpdate($u); }catch(Throwable $e){ logit('tg', $e->getMessage()); }
  }
  if(($off = inp('offset', null)) !== null && $off !== '') setSetting('tg_offset', (string)(int)$off);

  try{ tick(); }catch(Throwable $e){ logit('tick', $e->getMessage()); }

  $msgs = all("SELECT id, chat, text, kb FROM sop_outbox WHERE status='pending' ORDER BY id LIMIT 25");
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
