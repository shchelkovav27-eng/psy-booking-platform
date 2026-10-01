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
  /* Список для входа специалиста. Ничего личного: имя и направление и так на сайте школы. */
  $ex = bySurname(all("SELECT id, name, surname, direction, active FROM psy_experts WHERE active=1"));
  $closed = cfg('intake_closed') === '1';
  ok([
    'closed'     => $closed,
    'times'      => $closed ? [] : openTimes(),
    'duration'   => (int)cfg('duration'),
    'org'        => cfg('org'),
    'experts'    => $ex,
    'directions' => DIRECTIONS(),
    'topics'     => TOPICS(),
    'topicGroups'=> TOPIC_GROUPS(),
    'bot'        => cfg('bot_user'),
    'tz'         => cfg('tz'),
    'ver'        => appVer(),
    'now'        => now(),
  ]);
}

/* Отметка захода — публичная, без входа: страница записи сообщает, что её открыли.
   Ни адресов, ни устройств не пишем, только день, источник и шаг. */
if($action === 'hit'){
  hitVisit(inp('source'), inp('step'));
  ok();
}

/* ═══════════ КЛИЕНТ ═══════════ */
if($action === 'book'){
  /* Приём новых записей закрыт: уже назначенные встречи и бот работают до конца. */
  if(cfg('intake_closed') === '1')
    fail('Запись на консультации сейчас закрыта. Уже назначенные встречи состоятся в прежнее время.');
  $when    = inp('dt');
  $name    = mb_substr(inp('name'), 0, 100);
  $contact = mb_substr(inp('contact'), 0, 100);
  $cat     = mb_substr(inp('category'), 0, 100);
  $note    = mb_substr(inp('note'), 0, 300);
  if(!preg_match('/^\d{4}-\d{2}-\d{2}[ T]\d{2}:\d{2}(:\d{2})?$/', $when)) fail('Не разобрали время. Выберите слот заново.');
  $when = str_replace('T', ' ', $when);
  if(strlen($when) === 16) $when .= ':00';
  if(mb_strlen($name) < 2)    fail('Напишите, как к вам обращаться.');
  if(mb_strlen($contact) < 4) fail('Оставьте Telegram или телефон — туда придёт напоминание.');
  if(ts($when) < time())      fail('Это время уже прошло. Выберите другое.');

  $dir   = mb_substr(inp('direction'), 0, 60);
  $email = mb_substr(inp('email'), 0, 110);
  if($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) fail('Проверьте адрес почты — похоже, там опечатка.');
  $utm = [];
  foreach(['utm_source','utm_medium','utm_campaign','utm_term'] as $u) $utm[$u] = mb_substr((string)inp($u), 0, 110);
  $pair = (inp('pair') === '1' || inp('pair') === true);
  $gender = inp('gender') === 'm' ? 'm' : (inp('gender') === 'f' ? 'f' : '');
  $b = makeBooking($when, $name, $contact, $cat, $note, null, false, $dir, $email, $utm, $pair, '', '', $gender);
  if(!$b) fail($pair
    ? 'На это время специалист для парной консультации уже занят. Выберите другое время.'
    : ($gender !== ''
        ? 'На это время нет свободного специалиста, который работает с ' .
          ($gender === 'm' ? 'мужчинами' : 'женщинами') . '. Выберите другое время.'
        : 'Это время только что заняли. Выберите другое, пожалуйста.'));
  $e = expert($b['expert_id']);
  histAdd($b['id'], 'create', 'Запись создана. Специалист: ' . ($e['name'] ?? '—'), actor('client', '', 'site'));
  ok(['booking' => [
    'code'   => $b['code'],
    'dt'     => $b['dt'],
    'expert' => $e['name'],
    'link'   => $b['link'],
    'tg'     => botLink('b' . $b['code']),
  ]]);
}

if($action === 'booking'){
  $code = strtoupper(inp('code'));
  $b = one("SELECT * FROM psy_bookings WHERE code=?", [$code]);
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
    'canMove'  => clientCanMove($b),
    'late'     => lateMoveNote($b),
  ]]);
}

if($action === 'feedback'){
  $b = one("SELECT * FROM psy_bookings WHERE code=?", [strtoupper(inp('code'))]);
  if(!$b) fail('Запись не найдена.', 404);
  $score = (int)inp('score');
  if($score < 1 || $score > 5) fail('Поставьте оценку от 1 до 5.');
  $nps = (int)inp('nps'); if($nps < 0) $nps = 0; if($nps > 10) $nps = 10;
  q("UPDATE psy_bookings SET csat=?, fit=?, nps=?, comment=?, csat_at=? WHERE id=?",
    [$score, mb_substr(inp('fit'), 0, 20), $nps, mb_substr(inp('comment'), 0, 900), now(), $b['id']]);
  $e = expert($b['expert_id']);
  if($score <= 3) adminNotify("⚠️ Низкая оценка $score/5 (с сайта)\n" . $b['client_name'] . " → " . ($e['name'] ?? '—') . ", " . fmtRu($b['dt']));
  ok();
}

if($action === 'reschedule'){
  $b = one("SELECT * FROM psy_bookings WHERE code=?", [strtoupper(inp('code'))]);
  if(!$b) fail('Запись не найдена.', 404);
  if($b['status'] === 'cancelled') fail('Эта запись отменена — выберите время заново на главной.');
  if(!clientCanMove($b)) fail(ts($b['dt']) < time()
    ? 'Эту встречу перенести уже нельзя — напишите координатору, он подберёт новое время.'
    : 'Встреча уже прошла — переносить нечего.');
  $when = str_replace('T', ' ', inp('dt'));
  if(!preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}(:\d{2})?$/', $when)) fail('Не разобрали новое время.');
  if(strlen($when) === 16) $when .= ':00';
  if(ts($when) < time()) fail('Это время уже прошло. Выберите другое.');
  if($when === $b['dt'])  fail('Это то же самое время.');

  $nb = rescheduleBooking($b, $when, actor('client', '', 'site'));
  if(!$nb) fail('Это время только что заняли. Выберите другое — старое место пока за вами.');
  $e = expert($nb['expert_id']);
  ok(['booking' => [
    'code' => $nb['code'], 'dt' => $nb['dt'], 'expert' => $e['name'] ?? '—',
    'link' => $nb['link'], 'status' => $nb['status'], 'csat' => $nb['csat'],
    'duration' => (int)cfg('duration'), 'tg' => botLink('b' . $nb['code']),
    'canMove' => clientCanMove($nb), 'late' => lateMoveNote($nb),
  ]]);
}

if($action === 'cancel_by_code'){
  $b = one("SELECT * FROM psy_bookings WHERE code=?", [strtoupper(inp('code'))]);
  if(!$b) fail('Запись не найдена.', 404);
  if(!clientCanMove($b)) fail('Эту встречу отменить уже нельзя — напишите координатору.');
  cancelBooking($b, actor('client', '', 'site'));
  ok();
}

/* ═══════════ ВХОД ═══════════ */
if($action === 'login'){
  if(loginBlocked()) fail('Слишком много попыток. Попробуйте через 15 минут.', 429);
  $role = inp('role');
  $pin  = inp('pin');
  if($role === 'admin'){
    /* владельца проверяем первым: у него доступ шире */
    if($pin !== '' && cfg('owner_pin') !== '' && hash_equals((string)cfg('owner_pin'), $pin)){
      loginOk();
      ok(['token' => issueToken('owner'), 'role' => 'owner']);
    }
    if($pin !== '' && hash_equals((string)cfg('admin_pin'), $pin)){
      loginOk();
      ok(['token' => issueToken('admin'), 'role' => 'admin']);
    }
    loginFail(); fail('Код не подошёл.', 401);
  }
  if($role === 'expert'){
    /* Специалист просто выбирает себя из списка — кода нет.
       Ссылка на кабинет рабочая, но не публичная: её знают только свои. */
    $e = expert((int)inp('expertId'));
    if($e){
      loginOk();
      ok(['token' => issueToken('expert', $e['id']), 'role' => 'expert', 'name' => $e['name']]);
    }
    fail('Не нашли вас в списке. Обновите страницу.', 404);
  }
  fail('Неизвестная роль.');
}

/* ═══════════ КАБИНЕТ СПЕЦИАЛИСТА ═══════════ */
if($action === 'expert_state'){
  $a = requireRole('expert');
  $e = expert($a['expert_id']);
  if(!$e) fail('Специалист не найден.', 404);
  $slots = all("SELECT id, dt, booking_id FROM psy_slots WHERE expert_id=? AND dt > ? ORDER BY dt",
               [$e['id'], date('Y-m-d H:i:s', time() - 86400)]);
  /* оценки клиенток специалисту не показываем и не отдаём — их разбирает координатор */
  $bk = all("SELECT id, code, dt, client_name, contact, category, note, link, status,
                    rep_at, rep_upd, rep_req, rep_plan, rep_next
               FROM psy_bookings WHERE expert_id=? AND status NOT IN ('cancelled','searching') ORDER BY dt", [$e['id']]);
  foreach($bk as &$row){
    $full = one("SELECT contact, phone, tg_nick, confirm, confirm_at, pair FROM psy_bookings WHERE id=?", [$row['id']]);
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
                   'tgBound' => (bool)$e['tg_chat'], 'active' => (int)$e['active'],
                   'direction' => $e['direction'], 'topics' => topicsOf($e), 'pairs' => takesPairs($e),
                   'genders' => gendersOf($e),
                   'topicsSet' => !in_array(trim((string)$e['topics']), ['', '[]'], true),
                   'rulesAt'   => $e['rules_at'] ?? null],
    'rulesGate'=> rulesGate(),
    'allTopics'=> TOPICS(),
    'topicGroups' => TOPIC_GROUPS(),
    'tgLink'   => botLink('e' . $e['tg_key']),
    'slots'    => $slots,
    'bookings' => $bk,
    /* квоту и загрузку специалисту не отдаём вовсе — это кухня координатора,
       и в ответе сервера её тоже быть не должно, иначе видно в консоли браузера */
    'duration' => (int)cfg('duration'),
    'ver'      => appVer(),
    'now'      => now(),
  ]);
}

/* Подтверждение прихода клиента — со стороны специалиста */
/* Отмена встречи специалистом: клиентке уходит сообщение, слот возвращается в пул */
if($action === 'expert_cancel'){
  $a = requireRole('expert');
  $b = one("SELECT * FROM psy_bookings WHERE id=? AND expert_id=?", [(int)inp('id'), $a['expert_id']]);
  if(!$b) fail('Это не ваша встреча.', 404);
  if($b['status'] === 'cancelled') fail('Встреча уже отменена.');
  if(ts($b['dt']) < time()) fail('Встреча уже прошла — отменять нечего. Отметьте, состоялась ли она.');
  $e = expert($a['expert_id']);
  $why = mb_substr(inp('reason'), 0, 200);
  if(!cancelBooking($b, actor('expert', $e['name']))) fail('Не получилось отменить.');
  if($why !== '') histAdd($b['id'], 'cancel', 'Причина: ' . $why, actor('expert', $e['name']));
  adminNotify("❗️ Специалист отменил встречу\n" . ($e['name'] ?? '') . " — " . $b['client_name'] .
    ", " . fmtRu($b['dt']) . ($why !== '' ? "\nПричина: " . $why : '') .
    "\nКлиентке сообщение отправлено, слот вернулся в общий пул.");
  ok();
}

if($action === 'confirm_client'){
  $a = auth();
  if(!$a) fail('Нужен вход.', 401);
  $b = one("SELECT * FROM psy_bookings WHERE id=?", [(int)inp('id')]);
  if(!$b) fail('Встреча не найдена.', 404);
  if($a['role'] === 'expert' && (int)$a['expert_id'] !== (int)$b['expert_id']) fail('Это не ваша встреча.', 403);
  $val = inp('value') === 'no' ? 'no' : (inp('value') === '' ? '' : 'yes');
  q("UPDATE psy_bookings SET confirm=?, confirm_at=? WHERE id=?", [$val, $val === '' ? null : now(), $b['id']]);
  $e = expert($b['expert_id']);
  histAdd($b['id'], 'confirm', $val === 'yes' ? 'Подтверждён приход клиента'
    : ($val === 'no' ? 'Не дозвонились до клиента' : 'Отметка о подтверждении снята'), actorAuth());
  if($val === 'no') adminNotify("📵 Клиент не отвечает\n" . $b['client_name'] . ", " . fmtRu($b['dt']) .
    "\nСпециалист: " . ($e['name'] ?? '—') . (!empty($b['phone']) ? "\nТелефон: " . $b['phone'] : ''));
  ok();
}

if($action === 'slots_add'){
  $a      = requireRole('expert');
  /* Без ссылки на свою комнату время открывать нельзя: клиентке некуда прийти */
  $me = expert($a['expert_id']);
  if(!$me || !ownRoom($me)) fail('Сначала добавьте ссылку на свою комнату для встреч — без неё время не открывается.');
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
      try{
        $st = q("INSERT IGNORE INTO psy_slots(expert_id, dt, created_at) VALUES(?,?,?)", [$a['expert_id'], $dt, now()]);
        $n += $st->rowCount();
      }catch(Throwable $e){}
    }
  }
  if($n > 0) noticeFirstSlots($a['expert_id']);   /* первое в жизни открытое время — сказать кураторам */
  ok(['added' => $n, 'busy' => $busy]);
}

if($action === 'slot_del'){
  $a = requireRole('expert');
  q("DELETE FROM psy_slots WHERE id=? AND expert_id=? AND booking_id IS NULL", [(int)inp('id'), $a['expert_id']]);
  ok();
}

if($action === 'save_link'){
  $a = requireRole('expert');
  $l = trim(mb_substr(inp('link'), 0, 290));
  /* Ссылка обязательна: стереть её — значит остаться без места встречи,
     а открытое время сразу перестанет показываться клиенткам. */
  if($l === '') fail('Ссылка на вашу комнату нужна обязательно — без неё клиентке некуда прийти.');
  if(!preg_match('~^https?://~i', $l)) fail('Ссылка должна начинаться с https://');
  auditExpert($a['expert_id'], 'link', expert($a['expert_id'])['link'], $l, 'expert');
  q("UPDATE psy_experts SET link=? WHERE id=?", [$l, $a['expert_id']]);
  /* будущие записи сразу переезжают на новую комнату */
  $moved = 0;
  foreach(all("SELECT * FROM psy_bookings WHERE expert_id=? AND status<>'cancelled' AND dt > ?",
              [$a['expert_id'], now()]) as $b){
    $before = $b['link'];
    $nb = syncMeetingLink($b);
    if($nb['link'] !== $before) $moved++;
  }
  ok(['moved' => $moved]);
}

/* Специалист подтвердил, что прочёл договорённости: до этого его время не показывается */
if($action === 'accept_rules'){
  $a = requireRole('expert');
  $e = expert($a['expert_id']);
  if(!$e) fail('Специалист не найден.', 404);
  if(empty($e['rules_at'])){
    q("UPDATE psy_experts SET rules_at=? WHERE id=?", [now(), $e['id']]);
    auditExpert($e['id'], 'rules', '', now(), 'expert');
    logit('rules', 'договорённости принял: ' . $e['name']);
  }
  ok(['rulesAt' => now()]);
}

if($action === 'save_topics'){
  $a = requireRole('expert');
  /* Темы правим только если их прислали. Раньше сюда приходил запрос на смену пола
     клиентов без списка тем — и темы затирались пустотой, то есть «берусь за любую». */
  $raw = inp('topics', null);
  $t = is_array($raw) ? $raw : [];
  $sent = count($t);
  $touchTopics = ($raw !== null);
  /* Кабинет мог остаться открытым до обновления списка и прислать прежние формулировки —
     разворачиваем их в новые вместо того, чтобы молча ничего не сохранить. */
  $t = array_values(array_intersect(TOPICS(), expandTopics($t)));
  /* Пустой список означает «берётся за любую тему» — это можно только выбрать осознанно,
     а не получить из-за того, что ни одно присланное название не опознали. */
  if(!$t && $sent) fail('Не удалось разобрать список тем — обновите страницу и отметьте заново.');
  $was = expert($a['expert_id']);
  if($touchTopics){
    auditExpert($a['expert_id'], 'topics', $was['topics'], json_encode($t, JSON_UNESCAPED_UNICODE), 'expert');
    q("UPDATE psy_experts SET topics=? WHERE id=?", [json_encode($t, JSON_UNESCAPED_UNICODE), $a['expert_id']]);
  }else{
    $t = topicsOf($was);
  }
  $p = inp('pairs', null);
  if($p !== null){
    auditExpert($a['expert_id'], 'pairs', (int)($was['pairs'] ?? 0), ($p === '1' || $p === 1 || $p === true) ? 1 : 0, 'expert');
    q("UPDATE psy_experts SET pairs=? WHERE id=?", [($p === '1' || $p === 1 || $p === true) ? 1 : 0, $a['expert_id']]);
  }
  $g = inp('genders', null);
  if($g !== null && in_array($g, ['any','f','m'], true)){
    auditExpert($a['expert_id'], 'genders', gendersOf($was), $g, 'expert');
    q("UPDATE psy_experts SET genders=? WHERE id=?", [$g, $a['expert_id']]);
  }
  ok(['topics' => $t]);
}

/* Координатор может проставить парность за тех, кто ещё не заходил */
/* Пауза показа: слоты специалиста перестают показываться клиенткам,
   при этом он работает как обычно — кабинет, встречи и отчёты на месте. */
/* Личная квота: сколько клиентов берёт конкретный специалист */
/* Сводка: посмотреть сейчас или разослать вручную */
if($action === 'digest_preview'){
  requireRole('admin');
  ok(['text' => dailyDigest(inp('day') ?: null)]);
}
if($action === 'digest_send'){
  requireRole('admin');
  $n = sendDigest(inp('day') ?: null);
  ok(['sent' => $n]);
}

/* Приоритет специалиста: кому отдавать клиентов первым */
if($action === 'expert_priority'){
  requireRole('admin');            /* приоритет — рабочий рычаг, кураторам он тоже нужен */
  $e = expert((int)inp('id'));
  if(!$e) fail('Специалист не найден.', 404);
  $v = (int)inp('value');
  if($v < 1 || $v > 3) fail('Приоритет — от 1 до 3.');
  auditExpert($e['id'], 'priority', priorityOf($e), $v, auth()['role']);
  q("UPDATE psy_experts SET priority=? WHERE id=?", [$v, $e['id']]);
  ok(['priority' => $v]);
}

/* Тестовая карточка: на ней проверяют работу системы, поэтому уведомления кураторам с неё не уходят */
if($action === 'expert_test'){
  requireRole('owner');
  $e = expert((int)inp('id'));
  if(!$e) fail('Специалист не найден.', 404);
  $v = empty($e['is_test']) ? 1 : 0;
  auditExpert($e['id'], 'is_test', (int)($e['is_test'] ?? 0), $v, auth()['role']);
  q("UPDATE psy_experts SET is_test=? WHERE id=?", [$v, $e['id']]);
  ok(['test' => $v]);
}

/* Правка имени: опечатки чинит куратор, не дожидаясь владельца */
if($action === 'expert_rename'){
  requireRole('admin');
  $e = expert((int)inp('id'));
  if(!$e) fail('Специалист не найден.', 404);
  $n = trim(preg_replace('/\s+/u', ' ', (string)inp('name')));
  if(mb_strlen($n) < 3) fail('Впишите имя и фамилию.');
  if(mb_strlen($n) > 110) fail('Слишком длинное имя.');
  $sn = surnameOf($n, (string)($e['slug'] ?? ''));
  $n  = orderName($n, $sn);
  if($n === $e['name']) ok(['name' => $n]);
  if(one("SELECT id FROM psy_experts WHERE name=? AND id<>?", [$n, $e['id']]))
    fail('Специалист с таким именем уже есть.');
  auditExpert($e['id'], 'name', $e['name'], $n, auth()['role']);
  /* помечаем как поправленное руками — импорт из таблицы школы это имя не тронет */
  q("UPDATE psy_experts SET name=?, surname=?, name_fixed=1 WHERE id=?", [$n, $sn, $e['id']]);
  logit('expert', 'переименование: ' . $e['name'] . ' → ' . $n);
  ok(['name' => $n]);
}

if($action === 'expert_quota'){
  requireRole('admin');            /* квоту куратор двигает по ходу потока, не дожидаясь владельца */
  $e = expert((int)inp('id'));
  if(!$e) fail('Специалист не найден.', 404);
  $q = (int)inp('quota');
  if($q < 0 || $q > 200) fail('Квота должна быть от 0 до 200. Ноль — брать общую из настроек.');
  auditExpert($e['id'], 'quota', (int)($e['quota'] ?? 0), $q, auth()['role']);
  q("UPDATE psy_experts SET quota=? WHERE id=?", [$q > 0 ? $q : null, $e['id']]);
  ok(['quota' => quotaOf($e['id'])]);
}

if($action === 'expert_pause'){
  requireRole('owner');
  $e = expert((int)inp('id'));
  if(!$e) fail('Специалист не найден.', 404);
  $new = (int)($e['paused'] ?? 0) ? 0 : 1;
  q("UPDATE psy_experts SET paused=? WHERE id=?", [$new, $e['id']]);
  /* Специалисту об этом не пишем: закрыть или открыть показ — внутреннее решение школы,
     а «вас скрыли» человек читает как оценку своей работы. След остаётся в журнале профиля. */
  auditExpert($e['id'], 'paused', (int)($e['paused'] ?? 0), $new, actorLabel(actorAuth()));
  ok(['paused' => $new]);
}

if($action === 'expert_pairs'){
  requireRole('owner');
  q("UPDATE psy_experts SET pairs = 1 - COALESCE(pairs,0) WHERE id=?", [(int)inp('id')]);
  ok();
}

if($action === 'report'){
  $a = requireRole('expert');
  $b = one("SELECT * FROM psy_bookings WHERE id=? AND expert_id=?", [(int)inp('id'), $a['expert_id']]);
  if(!$b) fail('Встреча не найдена.', 404);
  $st = inp('status');
  if(!in_array($st, ['held', 'noshow', 'cancelled'], true)) $st = 'held';
  $stTxt = ['held' => 'состоялась', 'noshow' => 'клиент не пришёл', 'cancelled' => 'отменена'][$st];
  $new = ['rep_req' => mb_substr(inp('req'), 0, 380),
          'rep_plan'=> mb_substr(inp('plan'), 0, 380),
          'rep_next'=> mb_substr(inp('next'), 0, 380)];
  $edit = !empty($b['rep_at']);          /* отчёт уже сдавали — значит, это правка */

  if($edit){
    /* Первое время сдачи не трогаем: по нему считается дисциплина. Пишем время правки. */
    q("UPDATE psy_bookings SET status=?, rep_req=?, rep_plan=?, rep_next=?, rep_upd=? WHERE id=?",
      [$st, $new['rep_req'], $new['rep_plan'], $new['rep_next'], now(), $b['id']]);
  }else{
    q("UPDATE psy_bookings SET status=?, rep_req=?, rep_plan=?, rep_next=?, rep_at=? WHERE id=?",
      [$st, $new['rep_req'], $new['rep_plan'], $new['rep_next'], now(), $b['id']]);
  }

  if(!$edit){
    histAdd($b['id'], 'report', 'Отчёт сдан. Итог встречи: ' . $stTxt, actorAuth());
  }else{
    /* Прежний текст не теряем — он остаётся в истории записи, чтобы куратор видел, что поправили */
    $labels = ['rep_req' => 'Запрос клиента', 'rep_plan' => 'Гипотеза и план', 'rep_next' => 'Что дальше'];
    $changed = false;
    foreach($labels as $f => $label){
      if((string)$b[$f] === (string)$new[$f]) continue;
      $changed = true;
      $was = trim((string)$b[$f]);
      histAdd($b['id'], 'report', 'Отчёт поправлен, «' . $label . '». Было: ' . ($was === '' ? '— пусто —' : $was), actorAuth());
    }
    if((string)$b['status'] !== $st){
      $changed = true;
      histAdd($b['id'], 'report', 'Отчёт поправлен: итог встречи теперь «' . $stTxt . '»', actorAuth());
    }
    if(!$changed) histAdd($b['id'], 'report', 'Отчёт открывали на правку, текст не изменился', actorAuth());
  }
  ok(['edited' => $edit]);
}

/* ═══════════ КООРДИНАТОР ═══════════ */
if($action === 'admin_state'){
  requireRole('admin');
  $experts = bySurname(all("SELECT * FROM psy_experts"));
  $rows = [];
  foreach($experts as $e){
    $rows[] = [
      'id'       => $e['id'],
      'name'     => $e['name'],
      'pin'      => $e['pin'],
      'link'     => $e['link'],
      'active'   => (int)$e['active'],
      'direction'=> $e['direction'],
      'tgUser'   => $e['tg_user'],
      'onSite'   => (int)$e['on_site'],
      'pairs'    => takesPairs($e),
      'paused'   => (int)($e['paused'] ?? 0),
      'genders'  => gendersOf($e),
      'topics'   => topicsOf($e),
      'tgBound'  => (bool)$e['tg_chat'],
      'tgLink'   => botLink('e' . $e['tg_key']),
      'slots'    => (int)one("SELECT COUNT(*) c FROM psy_slots WHERE expert_id=? AND booking_id IS NULL AND dt>?", [$e['id'], now()])['c'],
      'load'     => loadOf($e['id']),
      'quota'    => quotaOf($e),
      'priority' => priorityOf($e),
      'test'     => (int)($e['is_test'] ?? 0),
      'rulesAt'  => $e['rules_at'] ?? null,
      'nameFixed'=> (int)($e['name_fixed'] ?? 0),
      'ownQuota' => (int)($e['quota'] ?? 0),
      'debt'     => reportDebt($e['id']),
    ];
  }
  $bk = all("SELECT b.*, e.name ename FROM psy_bookings b LEFT JOIN psy_experts e ON e.id=b.expert_id ORDER BY b.dt DESC");
  $changed = [];
  foreach(all("SELECT booking_id FROM psy_history WHERE kind='expert'") as $h) $changed[(int)$h['booking_id']] = 1;
  foreach($bk as &$row){
    $row['changed'] = isset($changed[(int)$row['id']]);
    $ex = expert($row['expert_id']);
    $own = $ex ? trim((string)$ex['link']) : '';
    $l = (string)$row['link'];
    /* «своя» — ссылка совпадает с комнатой специалиста; «старая» — осталась
       от прежних времён и на сегодняшнюю комнату не похожа */
    $row['linkKind'] = $l === '' ? 'none' : (($own !== '' && $l === $own) ? 'own' : 'other');
    $row['phone'] = bookingPhone($row);
    $row['tgNick'] = $row['tg_nick'] ?? '';
    $row['confirm'] = $row['confirm'] ?? '';
    $row['hook'] = (string)($row['hook_status'] ?? '');
  }
  unset($row);
  $hist = all("SELECT h.*, b.code, b.client_name FROM psy_history h
                 LEFT JOIN psy_bookings b ON b.id = h.booking_id
                ORDER BY h.id DESC LIMIT 40");
  foreach($hist as &$hrow)
    $hrow = array_merge($hrow, who(actor($hrow['who'] ?? '', $hrow['who_name'] ?? '', $hrow['via'] ?? '')));
  unset($hrow);

  /* сводка по составу: кто есть, кто работает, кому сколько должны */
  $team = ['total' => 0, 'withSlots' => 0, 'withTg' => 0, 'withHeld' => 0,
           'held' => 0, 'rows' => []];
  foreach($experts as $ex){
    if(!$ex['active']) continue;
    $team['total']++;
    $slotsFree = (int)one("SELECT COUNT(*) c FROM psy_slots WHERE expert_id=? AND booking_id IS NULL AND dt > ?", [$ex['id'], now()])['c'];
    $slots = $slotsFree;
    /* состоявшейся считаем встречу, по которой сдан отчёт — тот же счёт, что и на дашборде */
    $held  = (int)one("SELECT COUNT(*) c FROM psy_bookings WHERE expert_id=? AND status='held' AND rep_at IS NOT NULL", [$ex['id']])['c'];
    if($slots && !(int)($ex['paused'] ?? 0)) $team['withSlots']++;
    if($ex['tg_chat']) $team['withTg']++;
    if($held){ $team['withHeld']++; $team['held'] += $held; }
    if($held || $slots) $team['rows'][] = [
      'name' => $ex['name'], 'slots' => $slotsFree, 'held' => $held, 'paused' => (int)($ex['paused'] ?? 0),
      'load' => loadOf($ex['id']), 'quota' => quotaOf($ex), 'priority' => priorityOf($ex),
      'left' => max(0, quotaOf($ex) - loadOf($ex['id'])),
      'tg' => (bool)$ex['tg_chat'], 'debt' => reportDebt($ex['id']),
    ];
  }
  usort($team['rows'], function($a, $b){ return $b['held'] - $a['held']; });
  /* свободные слоты — чтобы координатор записывал выбором, а не угадыванием */
  $freeSlots = all(
    "SELECT s.id, s.expert_id, s.dt, e.name ename FROM psy_slots s
       JOIN psy_experts e ON e.id = s.expert_id
      WHERE s.booking_id IS NULL AND e.active = 1 AND COALESCE(e.paused,0) = 0 AND s.dt > ?
      ORDER BY s.dt LIMIT 400", [now()]);
  $pending = (int)one("SELECT COUNT(*) c FROM psy_outbox WHERE status='pending'")['c'];
  $s = settings();
  $me = auth();
  ok([
    'role'     => $me['role'],
    'experts'  => $rows,
    'bookings' => $bk,
    'times'    => openTimes(),
    'settings' => [
      'quota' => (int)$s['quota'], 'duration' => (int)$s['duration'], 'admin_pin' => $s['admin_pin'],
      'tz' => $s['tz'], 'bot_user' => $s['bot_user'],
      'tg_on' => $s['tg_token'] !== '', 'relay_key' => $s['relay_key'],
      'remind24' => $s['remind24'], 'remind2' => $s['remind2'], 'remind15' => $s['remind15'],
      'digest_on' => $s['digest_on'], 'digest_at' => $s['digest_at'], 'ask_csat' => $s['ask_csat'], 'org' => $s['org'],
      'alert_email' => $s['alert_email'],
      'rules_gate' => $s['rules_gate'],
      'hook_on' => $s['hook_on'], 'hook_url' => $s['hook_url'],
      'sms_on' => $s['sms_on'], 'sms_email' => $s['sms_email'], 'sms_sign' => $s['sms_sign'],
      'sms_ready' => ($s['sms_email'] !== '' && $s['sms_key'] !== ''),
      'owner_pin' => $s['owner_pin'],
    ],
    'adminTgLink' => botLink('a' . $s['admin_key']),
    'freeSlots'   => $freeSlots,
    'history'     => $hist,
    'team'        => $team,
    'ver'         => appVer(),
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
  $tgn   = tgNickOf(inp('tg_nick'));
  if(mb_strlen($name) < 2) fail('Впишите имя клиентки.');
  if($phone !== '' && phoneOf($phone) === '') fail('Телефон не разобрали. Формат: +7 999 123-45-67');
  if($phone === '' && $tgn === '') fail('Нужен хотя бы один контакт — телефон или Telegram.');
  $contact = trim(($phone !== '' ? $phone : '') . ($phone !== '' && $tgn !== '' ? ', ' : '') . ($tgn !== '' ? '@' . $tgn : ''));
  if(ts($when) < time())      fail('Это время уже прошло.');

  $force  = (int)inp('expertId');
  $b = makeBooking($when, $name, $contact, mb_substr(inp('category'), 0, 100), mb_substr(inp('note'), 0, 300),
                   $force ?: null, true, mb_substr(inp('direction'), 0, 60), mb_substr(inp('email'), 0, 110), [],
                   inp('pair') === '1', $phone, $tgn,
                   inp('gender') === 'm' ? 'm' : (inp('gender') === 'f' ? 'f' : ''));
  if(!$b){
    fail($force
      ? 'У этого специалиста время занято или его нет в пуле. Выберите другое время или другого человека.'
      : 'На это время нет ни одного свободного слота. Выберите специалиста явно — слот откроется под него.');
  }
  $e = expert($b['expert_id']);
  histAdd($b['id'], 'create', 'Запись заведена вручную. Специалист: ' . ($e['name'] ?? '—'), actorAuth());
  ok(['booking' => [
    'code' => $b['code'], 'dt' => $b['dt'], 'expert' => $e['name'],
    'link' => $b['link'], 'tg' => botLink('b' . $b['code']),
    'sms'  => (empty($b['tg_chat']) && smsOn() && bookingPhone($b) !== ''),
  ]]);
}

/* Правка данных клиента: имя, связь, тема, запрос, почта.
   Время и специалист сюда не входят — для них есть перенос и замена,
   у которых свои проверки занятости и свои уведомления. */
if($action === 'booking_edit'){
  requireRole('admin');
  $b = one("SELECT * FROM psy_bookings WHERE id=?", [(int)inp('id')]);
  if(!$b) fail('Запись не найдена.', 404);

  $name  = mb_substr(trim(inp('name')), 0, 100);
  $phone = mb_substr(trim(inp('phone')), 0, 20);
  $tgn   = tgNickOf(inp('tg_nick'));
  $email = mb_substr(trim(inp('email')), 0, 110);
  $cat   = mb_substr(trim(inp('category')), 0, 100);
  $note  = mb_substr(trim(inp('note')), 0, 300);

  if(mb_strlen($name) < 2) fail('Впишите имя клиента.');
  if($phone !== '' && phoneOf($phone) === '') fail('Телефон не разобрали. Формат: +7 999 123-45-67');
  if($phone === '' && $tgn === '') fail('Нужен хотя бы один контакт — телефон или Telegram.');
  if($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) fail('Проверьте адрес почты — похоже, там опечатка.');

  $contact = trim(($phone !== '' ? $phone : '') . ($phone !== '' && $tgn !== '' ? ', ' : '') . ($tgn !== '' ? '@' . $tgn : ''));
  q("UPDATE psy_bookings SET client_name=?, phone=?, tg_nick=?, contact=?, email=?, category=?, note=? WHERE id=?",
    [$name, $phone, $tgn, $contact, $email, $cat, $note, $b['id']]);

  /* в историю пишем только то, что действительно изменилось */
  $was = ['имя' => $b['client_name'], 'телефон' => $b['phone'], 'Telegram' => $b['tg_nick'],
          'почта' => $b['email'], 'тема' => $b['category'], 'запрос' => $b['note']];
  $now = ['имя' => $name, 'телефон' => $phone, 'Telegram' => $tgn,
          'почта' => $email, 'тема' => $cat, 'запрос' => $note];
  $diff = [];
  foreach($was as $k => $v)
    if((string)$v !== (string)$now[$k])
      $diff[] = $k . ': ' . ((string)$v === '' ? '—' : $v) . ' → ' . ((string)$now[$k] === '' ? '—' : $now[$k]);
  if($diff) histAdd($b['id'], 'edit', 'Правка данных клиента. ' . implode('; ', $diff), actorAuth());

  ok(['changed' => count($diff)]);
}

/* Перенос силами координатора: время выбирается из реально свободных */
if($action === 'admin_reschedule'){
  requireRole('admin');
  $b = one("SELECT * FROM psy_bookings WHERE id=?", [(int)inp('id')]);
  if(!$b) fail('Запись не найдена.', 404);
  if($b['status'] === 'cancelled') fail('Запись отменена — переносить нечего.');
  $when = str_replace('T', ' ', inp('dt'));
  if(!preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}(:\d{2})?$/', $when)) fail('Выберите время из списка.');
  if(strlen($when) === 16) $when .= ':00';
  if(ts($when) < time()) fail('Это время уже прошло.');
  if($when === $b['dt'])  fail('Это то же самое время.');
  $nb = rescheduleBooking($b, $when, actorAuth());
  if(!$nb) fail('Это время только что заняли. Выберите другое — старое место пока за клиенткой.');
  $e = expert($nb['expert_id']);
  ok(['dt' => $nb['dt'], 'expert' => $e['name'] ?? '—', 'link' => $nb['link']]);
}

if($action === 'admin_cancel'){
  requireRole('admin');
  $b = one("SELECT * FROM psy_bookings WHERE id=?", [(int)inp('id')]);
  if(!$b) fail('Запись не найдена.', 404);
  cancelBooking($b, actorAuth());
  ok();
}

/* Удаление записи насовсем — для тестовых и ошибочных.
   Тихое: никому не пишем, это уборка, а не отмена встречи. */
if($action === 'booking_delete'){
  /* Удаление — только владельцу: действие необратимое и тихое.
     Кураторам оставлена отмена, о которой человек узнаёт. */
  $a = requireRole('owner');
  $b = one("SELECT * FROM psy_bookings WHERE id=?", [(int)inp('id')]);
  if(!$b) fail('Запись не найдена.', 404);
  /* Складываем копию в корзину: месяц можно вернуть */
  db()->exec("CREATE TABLE IF NOT EXISTS psy_trash(
    id INT AUTO_INCREMENT PRIMARY KEY, at DATETIME NOT NULL, who VARCHAR(20) DEFAULT '',
    code VARCHAR(8), data MEDIUMTEXT) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
  q("INSERT INTO psy_trash(at, who, code, data) VALUES(?,?,?,?)",
    [now(), $a['role'], $b['code'], json_encode($b, JSON_UNESCAPED_UNICODE)]);
  q("DELETE FROM psy_trash WHERE at < ?", [date('Y-m-d H:i:s', time() - 30*86400)]);

  q("UPDATE psy_slots SET booking_id=NULL WHERE booking_id=?", [$b['id']]);
  q("DELETE FROM psy_sent WHERE booking_id=?", [$b['id']]);
  q("UPDATE psy_tg SET booking_id=NULL WHERE booking_id=?", [$b['id']]);
  q("DELETE FROM psy_bookings WHERE id=?", [$b['id']]);
  logit('admin', 'удалена запись ' . $b['code'] . ' — ' . $b['client_name'] . ' (' . $a['role'] . ')');
  adminNotify("🗑 Удалена запись\n" . $b['client_name'] . ", " . fmtRu($b['dt']) .
    "\nЕё можно вернуть месяц: раздел «Записи», корзина.");
  ok();
}

/* Корзина: посмотреть и восстановить удалённое */
if($action === 'trash_list'){
  requireRole('owner');
  db()->exec("CREATE TABLE IF NOT EXISTS psy_trash(
    id INT AUTO_INCREMENT PRIMARY KEY, at DATETIME NOT NULL, who VARCHAR(20) DEFAULT '',
    code VARCHAR(8), data MEDIUMTEXT) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
  $rows = [];
  foreach(all("SELECT * FROM psy_trash ORDER BY id DESC LIMIT 50") as $r){
    $d = json_decode($r['data'], true) ?: [];
    $e = !empty($d['expert_id']) ? expert($d['expert_id']) : null;
    $rows[] = ['id' => $r['id'], 'at' => $r['at'], 'who' => $r['who'], 'code' => $r['code'],
               'name' => $d['client_name'] ?? '', 'dt' => $d['dt'] ?? '',
               'expert' => $e['name'] ?? '', 'category' => $d['category'] ?? '',
               'contact' => $d['phone'] ?: ($d['contact'] ?? '')];
  }
  ok(['list' => $rows]);
}

if($action === 'trash_restore'){
  requireRole('owner');
  $r = one("SELECT * FROM psy_trash WHERE id=?", [(int)inp('id')]);
  if(!$r) fail('В корзине этого нет.', 404);
  $d = json_decode($r['data'], true);
  if(!$d) fail('Копия повреждена.');
  if(one("SELECT id FROM psy_bookings WHERE code=?", [$d['code']])) fail('Такая запись уже есть — восстанавливать не нужно.');
  $b = makeBooking($d['dt'], $d['client_name'], $d['contact'], $d['category'], $d['note'],
                   (int)$d['expert_id'], true, $d['direction'] ?? '', $d['email'] ?? '', [],
                   !empty($d['pair']), $d['phone'] ?? '', $d['tg_nick'] ?? '', $d['gender'] ?? '');
  if(!$b) fail('Это время уже занято — сначала освободите его.');
  q("DELETE FROM psy_trash WHERE id=?", [$r['id']]);
  histAdd($b['id'], 'create', 'Запись восстановлена из корзины', actorAuth());
  ok(['code' => $b['code']]);
}

/* Перевыпуск ссылки на встречу: старая перестаёт работать,
   поэтому сразу пробуем донести новую всем, до кого дотягиваемся. */
if($action === 'unassign_booking'){
  requireRole('admin');
  $b = one("SELECT * FROM psy_bookings WHERE id=?", [(int)inp('id')]);
  if(!$b) fail('Запись не найдена.', 404);
  list($ok, $res) = unassignBooking($b, mb_substr(inp('reason'), 0, 200), actorAuth());
  if(!$ok) fail($res);
  ok();
}

if($action === 'assign_booking'){
  requireRole('admin');
  $b = one("SELECT * FROM psy_bookings WHERE id=?", [(int)inp('id')]);
  if(!$b) fail('Запись не найдена.', 404);
  $r = assignBooking($b, (int)inp('expertId'), actorAuth());
  if(!$r[0]) fail($r[1]);
  $e = expert($r[1]['expert_id']);
  ok(['expert' => $e['name'], 'link' => $r[1]['link'], 'via' => $r[2] ?? '']);
}

if($action === 'change_expert'){
  requireRole('admin');
  $b = one("SELECT * FROM psy_bookings WHERE id=?", [(int)inp('id')]);
  if(!$b) fail('Запись не найдена.', 404);
  if($b['status'] === 'cancelled') fail('Запись отменена — менять специалиста не в чем.');
  list($ok, $res) = changeExpert($b, (int)inp('expertId'), mb_substr(inp('reason'), 0, 200), actorAuth());
  if(!$ok) fail($res);
  $e = expert($res['expert_id']);
  ok(['expert' => $e['name'], 'dt' => $res['dt'], 'link' => $res['link']]);
}

/* Полная история по одной записи: что уходило, каким каналом, что менялось */
if($action === 'booking_log'){
  requireRole('admin');
  $b = one("SELECT * FROM psy_bookings WHERE id=?", [(int)inp('id')]);
  if(!$b) fail('Запись не найдена.', 404);
  $e = expert($b['expert_id']);
  $names = [
    'new'  => 'Подтверждение записи клиентке',
    'canc' => 'Сообщение клиентке об отмене',
    'r24'  => 'Напоминание клиентке за сутки',
    'r2'   => 'Напоминание клиентке за 2 часа',
    'r15'  => 'Напоминание клиентке за 15 минут',
    'ask'  => 'Просьба оценить встречу',
    'e2'   => 'Напоминание специалисту за 2 часа',
    'eask' => 'Вопрос специалисту: как прошла',
    'rep'  => 'Напоминание специалисту про отчёт',
  ];
  $via = ['tg' => 'Telegram', 'sms' => 'SMS', 'mail' => 'почта',
          'none' => 'не удалось доставить',
          'skip' => 'не отправляли — к моменту записи срок уже прошёл',
          'admin' => 'кураторам',
          '' => 'канал не записан'];
  $hist = all("SELECT * FROM psy_history WHERE booking_id=?", [$b['id']]);
  $hasCreate = false;
  foreach($hist as $x) if($x['kind'] === 'create') $hasCreate = true;

  $ev = [];
  /* у старых записей события «создана» в истории нет — показываем как раньше, без автора */
  if(!$hasCreate)
    $ev[] = ['at' => $b['created_at'], 'what' => 'Запись создана',
             /* кто её вёл изначально, у старых записей не сохранено — показываем сегодняшнего */
             'how' => $e ? ('специалист сейчас: ' . $e['name']) : ''] + who(actor('system'));
  foreach(all("SELECT * FROM psy_sent WHERE booking_id=?", [$b['id']]) as $x)
    $ev[] = ['at' => $x['at'], 'what' => $names[$x['kind']] ?? $x['kind'],
             'how' => $via[$x['via'] ?? ''] ?? $x['via']] + who(actor('system'));
  foreach($hist as $x)
    $ev[] = ['at' => $x['at'], 'what' => $x['txt'], 'how' => ''] +
            who(actor($x['who'] ?? '', $x['who_name'] ?? '', $x['via'] ?? ''));
  if($b['csat_at']) $ev[] = ['at' => $b['csat_at'], 'what' => 'Клиентка оценила встречу на ' . $b['csat'] . ' из 5',
                             'how' => $b['comment'] ? '«' . $b['comment'] . '»' : ''] + who(actor('client'));
  if($b['rep_at'])  $ev[] = ['at' => $b['rep_at'],  'what' => 'Специалист сдал отчёт',
                             'how' => $b['rep_plan']] + who(actor('expert', $e['name'] ?? ''));
  usort($ev, function($x, $y){ return strcmp($x['at'], $y['at']); });
  ok(['events' => $ev]);
}

/* Последние строки служебного журнала: без него любая неудача видна только всплывашкой */
/* Статистика для координатора: итоги, недели, источники, воронка */
if($action === 'stats'){
  requireRole('admin');
  $to   = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)inp('to'))   ? inp('to')   : date('Y-m-d');
  $from = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)inp('from')) ? inp('from') : date('Y-m-01', strtotime($to));
  ok(['stats' => statsFor($from, $to)]);
}

if($action === 'logs'){
  requireRole('owner');
  $kind = mb_substr(inp('kind'), 0, 20);
  $rows = $kind !== ''
    ? all("SELECT at, kind, txt FROM psy_log WHERE kind=? ORDER BY id DESC LIMIT 60", [$kind])
    : all("SELECT at, kind, txt FROM psy_log ORDER BY id DESC LIMIT 60");
  ok(['rows' => $rows]);
}

if($action === 'sms_booking'){
  requireRole('admin');
  $b = one("SELECT * FROM psy_bookings WHERE id=?", [(int)inp('id')]);
  if(!$b) fail('Запись не найдена.', 404);
  if(!smsOn()){
    histAdd($b['id'], 'sms', 'SMS не отправлена: SMS выключены или не настроены', actorAuth());
    fail('SMS выключены или не настроены — включите их в настройках.');
  }
  $to = bookingPhone($b);
  if($to === ''){
    histAdd($b['id'], 'sms', 'SMS не отправлена: телефона нет, в контакте «' . $b['contact'] . '»', actorAuth());
    fail('Телефона у этой клиентки нет — в контакте «' . $b['contact'] . '». Впишите номер через «Редактировать данные».');
  }
  $text = mb_substr(inp('text'), 0, 300);
  if($text === ''){
    $text = 'Напоминаем: ' . date('d.m', ts($b['dt'])) . ' в ' . date('H:i', ts($b['dt'])) . tzShort() .
            ' встреча. Ссылка: ' . smsUrl($b);
  }
  if(!smsSend($to, $text)){
    $why = trim((string)($GLOBALS['PSY_SMS_ERR'] ?? ''));
    /* молчаливых отказов быть не должно: неудачу видно и на экране, и в истории записи */
    histAdd($b['id'], 'sms', 'SMS не отправлена' . ($why !== '' ? ': ' . mb_substr($why, 0, 200) : ''), actorAuth());
    fail('SMS не ушла' . ($why !== '' ? ': ' . $why : '. Проверьте баланс и подпись в настройках.'));
  }
  $parts = smsParts($text);
  histAdd($b['id'], 'sms', 'SMS отправлена вручную: ' . mb_substr($text, 0, 120), actorAuth());
  ok(['sent' => true, 'parts' => $parts]);
}

/* Кто подключён к боту и кем является */
if($action === 'tg_list'){
  requireRole('admin');
  $rows = [];
  foreach(all("SELECT * FROM psy_tg ORDER BY updated_at DESC") as $r){
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
  $cur = one("SELECT * FROM psy_tg WHERE chat=?", [$chat]);
  if(!$cur) fail('Такое подключение не найдено.', 404);
  if($role === 'admin'){
    /* был специалистом — отвязываем от карточки специалиста */
    if($cur['expert_id']) q("UPDATE psy_experts SET tg_chat=NULL WHERE id=?", [$cur['expert_id']]);
    q("UPDATE psy_tg SET role='admin', expert_id=NULL, booking_id=NULL, state='' WHERE chat=?", [$chat]);
    send($chat, "✅ Вам открыли доступ куратора.\n\nСюда будут приходить все новые записи, отмены, неявки и оценки ниже 4.");
  }else{
    q("UPDATE psy_tg SET role=? WHERE chat=?", [$role, $chat]);
  }
  ok();
}

if($action === 'tg_unbind'){
  requireRole('admin');
  $chat = (string)inp('chat');
  $cur = one("SELECT * FROM psy_tg WHERE chat=?", [$chat]);
  if($cur && $cur['expert_id']) q("UPDATE psy_experts SET tg_chat=NULL WHERE id=?", [$cur['expert_id']]);
  q("UPDATE psy_bookings SET tg_chat=NULL WHERE tg_chat=?", [$chat]);
  q("DELETE FROM psy_tg WHERE chat=?", [$chat]);
  ok();
}

if($action === 'sms_check'){
  requireRole('owner');
  foreach(['sms_email','sms_sign'] as $k){ $v = inp($k); if($v !== '') setSetting($k, $v); }
  $sk = inp('sms_key'); if($sk !== '') setSetting('sms_key', $sk);
  try{
    $bal  = smsApi('/balance');
    $signs = [];
    try{ foreach((array)(smsApi('/sign/list')['data'] ?? []) as $x) $signs[] = $x['name'] ?? ''; }catch(Throwable $e){}
    $to = inp('phone');
    $sent = false;
    if($to !== ''){
      $p = phoneOf($to);
      if($p === '') fail('Не разобрали номер. Формат: +7 999 123-45-67');
      smsApi('/sms/send', ['number' => $p, 'text' => 'Проверка связи: система записи на консультации.',
                           'sign' => cfg('sms_sign') ?: 'SMS Aero', 'channel' => 'DIRECT']);
      $sent = true;
    }
    ok(['balance' => $bal['data']['balance'] ?? null, 'signs' => $signs, 'sent' => $sent]);
  }catch(Throwable $e){ fail($e->getMessage()); }
}

if($action === 'hook_test'){
  requireRole('owner');
  $url = inp('url');
  if($url !== '') setSetting('hook_url', $url);
  list($ok, $msg) = hookPost([
    'name' => 'Проверка связи (тест агрегатора)', 'phone' => '+70000000000',
    'utm_source' => 'test', 'utm_medium' => 'test', 'utm_campaign' => 'test', 'utm_term' => 'test',
  ]);
  if(!$ok) fail('Не приняли: ' . $msg);
  ok(['answer' => $msg]);
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

if($action === 'fix_surnames'){
  requireRole('owner');
  backfillSurnames();
  ok();
}

if($action === 'expert_add'){
  requireRole('admin');            /* состав пополняет и куратор: люди приходят в поток по ходу дела */
  $n = mb_substr(inp('name'), 0, 110);
  $l = mb_substr(inp('link'), 0, 290);
  $d = normDirection(inp('direction'));
  if(mb_strlen($n) < 2) fail('Впишите имя и фамилию.');
  if(one("SELECT id FROM psy_experts WHERE name=? OR name=?", [$n, orderName($n)]))
    fail('Такой специалист уже есть в списке.');
  /* Кода доступа у специалистов нет — вход по выбору себя из списка. Поле в базе оставлено
     на случай возврата входа по коду, поэтому заполняем его случайным значением. */
  $p = mb_substr(inp('pin'), 0, 20);
  if($p === '') $p = (string)random_int(1000, 9999);
  $sn = surnameOf($n);
  q("INSERT INTO psy_experts(name,pin,link,direction,tg_key,active,created_at,surname) VALUES(?,?,?,?,?,1,?,?)",
    [orderName($n, $sn), $p, $l, $d, substr(bin2hex(random_bytes(8)), 0, 10), now(), $sn]);
  $id = (int)db()->lastInsertId();
  auditExpert($id, 'created', '', $n . ($d ? ' · ' . $d : ''), auth()['role']);
  ok(['id' => $id, 'name' => $n]);
}

if($action === 'expert_toggle'){
  requireRole('owner');
  q("UPDATE psy_experts SET active = 1 - active WHERE id=?", [(int)inp('id')]);
  ok();
}

if($action === 'expert_del'){
  requireRole('owner');
  $id = (int)inp('id');
  if(one("SELECT id FROM psy_bookings WHERE expert_id=? AND status<>'cancelled'", [$id]))
    fail('У специалиста есть записи — удалять нельзя, скройте её из пула.');
  q("DELETE FROM psy_slots WHERE expert_id=? AND booking_id IS NULL", [$id]);
  q("DELETE FROM psy_experts WHERE id=?", [$id]);
  ok();
}

if($action === 'settings_save'){
  requireRole('owner');
  /* Поля, которые можно осознанно очистить: пустая строка — это значение, а не «не трогать» */
  $clearable = ['alert_email' => 1, 'hook_url' => 1];
  foreach(['quota','duration','tz','remind24','remind2','remind15','ask_csat','digest_on','digest_at','org','alert_email','sticky',
           'ask_topic','ask_pair','rules_gate',
           'hook_on','hook_url','sms_on','sms_email','sms_sign'] as $k){
    $v = inp($k, null);
    if($v === null) continue;
    if($v === '' && !isset($clearable[$k])) continue;
    /* Пишем в журнал, что именно поменялось: однажды передача заявок в агрегатор
       выключилась незаметно, и понять, когда и кто, было нельзя. */
    $before = (string)cfg($k);
    if($before !== (string)$v)
      logit('settings', $k . ': «' . mb_substr($before, 0, 60) . '» → «' . mb_substr((string)$v, 0, 60) . '»');
    setSetting($k, $v);
  }
  $sk = inp('sms_key');
  if($sk !== '') setSetting('sms_key', $sk);
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

if($action === 'wipe'){
  requireRole('owner');
  q("DELETE FROM psy_bookings"); q("DELETE FROM psy_slots"); q("DELETE FROM psy_sent");
  q("DELETE FROM psy_outbox"); q("UPDATE psy_tg SET booking_id=NULL WHERE role='client'");
  ok();
}

/* База клиентов: люди, собранные из заявок по совпадающим контактам */
if($action === 'clients'){
  requireRole('admin');
  ok(['clients' => clientBase()]);
}

if($action === 'clients_csv'){
  requireRole('admin');
  header('Content-Type: text/csv; charset=utf-8');
  header('Content-Disposition: attachment; filename="klienty-' . date('Y-m-d') . '.csv"');
  $out = fopen('php://output', 'w');
  fwrite($out, "\xEF\xBB\xBF");
  fputcsv($out, ['Имя','Телефон','Telegram','Почта','Записей','Прошло','Не дошли','Впереди',
                 'Первая запись','Последняя запись','Последний специалист','Средняя оценка','Источник'], ';');
  foreach(clientBase() as $c){
    fputcsv($out, [$c['name'], implode(' ', $c['phone']), implode(' ', array_map(function($t){ return '@' . $t; }, $c['tg'])),
      implode(' ', $c['mail']), $c['total'], $c['done'], $c['cancelled'] + $c['noshow'], $c['ahead'],
      $c['first'], $c['last'], $c['lastExpert'], $c['csat'], $c['source']], ';');
  }
  fclose($out);
  exit;
}

if($action === 'csv'){
  requireRole('admin');
  header('Content-Type: text/csv; charset=utf-8');
  header('Content-Disposition: attachment; filename="zapisi-' . date('Y-m-d') . '.csv"');
  $out = fopen('php://output', 'w');
  fwrite($out, "\xEF\xBB\xBF");
  fputcsv($out, ['Когда','Клиент','Связь','Запрос','Специалист','Статус','CSAT','Подошёл','NPS','Комментарий',
                 'Отчёт: запрос','Гипотеза и план','Что дальше','Код','Создана'], ';');
  foreach(all("SELECT b.*, e.name ename FROM psy_bookings b LEFT JOIN psy_experts e ON e.id=b.expert_id ORDER BY b.dt") as $b){
    fputcsv($out, [$b['dt'], $b['client_name'], $b['contact'], $b['category'], $b['ename'], $b['status'],
      $b['csat'], $b['fit'], $b['nps'], $b['comment'], $b['rep_req'], $b['rep_plan'], $b['rep_next'],
      $b['code'], $b['created_at']], ';');
  }
  fclose($out);
  exit;
}

/* ═══════════ РЕЛЕ TELEGRAM ═══════════ */
if($action === 'relay'){
  $key = inp('key');
  if($key === '' || !hash_equals((string)cfg('relay_key'), $key)) fail('Нет доступа.', 403);
  setSetting('last_relay', now());

  /* подтверждения отправки */
  foreach((array)inp('ack', []) as $id) q("UPDATE psy_outbox SET status='sent', sent_at=? WHERE id=?", [now(), (int)$id]);
  foreach((array)inp('failed', []) as $f){
    q("UPDATE psy_outbox SET tries=tries+1, err=?, status=IF(tries>=4,'failed','pending') WHERE id=?",
      [mb_substr((string)($f['err'] ?? 'error'), 0, 280), (int)($f['id'] ?? 0)]);
  }
  /* входящие апдейты */
  foreach((array)inp('updates', []) as $u){
    try{ handleUpdate($u); }catch(Throwable $e){ logit('tg', $e->getMessage()); }
  }
  if(($off = inp('offset', null)) !== null && $off !== '') setSetting('tg_offset', (string)(int)$off);

  try{ tick(); }catch(Throwable $e){ logit('tick', $e->getMessage()); }

  $msgs = all("SELECT id, chat, text, kb FROM psy_outbox WHERE status='pending' ORDER BY id LIMIT 25");
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
