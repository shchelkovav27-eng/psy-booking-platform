<?php
/* Планировщик напоминаний.
   Запуск из панели хостинга (Cron):  php ~/public_html/zapis/cron.php
   Через веб доступен только с ключом реле:  cron.php?key=…                     */
require_once __DIR__ . '/lib.php';

$cli = (php_sapi_name() === 'cli');
initTables();
settings();

if(!$cli){
  $key = $_GET['key'] ?? '';
  if($key === '' || !hash_equals((string)cfg('relay_key'), $key)){
    http_response_code(403);
    exit('403');
  }
  header('Content-Type: text/plain; charset=utf-8');
}

try{
  tick();
  $pending = (int)one("SELECT COUNT(*) c FROM psy_outbox WHERE status='pending'")['c'];
  echo "ok " . now() . ", в очереди: $pending\n";
}catch(Throwable $e){
  logit('cron', $e->getMessage());
  echo "error: " . $e->getMessage() . "\n";
}
