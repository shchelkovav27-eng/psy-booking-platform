<?php
/* Ежедневная копия данных агрегатора: только таблицы psy_*.
   Запуск: php dbdump.php  (или из планировщика раз в сутки). */
require_once __DIR__ . '/lib.php';

function psyDump(){
  /* Путь считаем от самого файла, а не от HOME: в планировщике панели HOME пуст,
     и копия неделю подряд пыталась лечь в /backups/db — в корень диска. */
  $dir = defined('BACKUP_DIR') ? BACKUP_DIR : dirname(__DIR__, 2) . '/backups/db';
  if(!is_dir($dir)) mkdir($dir, 0700, true);

  /* пароль не светим в списке процессов — кладём во временный конфиг */
  $cnf = tempnam(sys_get_temp_dir(), 'my');
  file_put_contents($cnf, "[client]\nuser=" . DB_USER . "\npassword=\"" . DB_PASS . "\"\nhost=" . DB_HOST . "\n");
  chmod($cnf, 0600);

  $tables = [];
  foreach(all("SHOW TABLES LIKE 'psy\\_%'") as $r) $tables[] = implode('', array_values($r));
  if(!$tables){ @unlink($cnf); return [false, 'нет таблиц psy_*']; }

  $file = $dir . '/psy_' . date('Y-m-d_H-i') . '.sql.gz';
  $raw  = $dir . '/.psy_tmp_' . getmypid() . '.sql';
  /* Пишем в файл через --result-file и сжимаем сами: конвейер «mysqldump | gzip»
     на этом хостинге срывается с errno 11, и копия молча получалась битой.
     --no-tablespaces — иначе mysqldump требует привилегию PROCESS, которой
     у пользователя общего хостинга нет. */
  $err = tempnam(sys_get_temp_dir(), 'dumperr');
  $cmd = 'mysqldump --defaults-extra-file=' . escapeshellarg($cnf) .
         ' --single-transaction --quick --skip-lock-tables --no-tablespaces' .
         ' --result-file=' . escapeshellarg($raw) . ' ' .
         escapeshellarg(DB_NAME) . ' ' . implode(' ', array_map('escapeshellarg', $tables)) .
         ' 2>' . escapeshellarg($err);
  exec($cmd, $out, $code);
  @unlink($cnf);
  $why = trim((string)@file_get_contents($err));
  @unlink($err);

  /* «Dump completed» mysqldump пишет, только дойдя до конца: это признак целой копии */
  $whole = file_exists($raw) && filesize($raw) > 500 &&
           mb_strpos((string)@file_get_contents($raw, false, null, max(0, filesize($raw) - 200)), 'Dump completed') !== false;

  if($code === 0 && $whole){
    $in = @fopen($raw, 'rb'); $gz = @gzopen($file, 'wb9');
    if($in && $gz){ while(!feof($in)) gzwrite($gz, fread($in, 262144)); }
    if($in) fclose($in);
    if($gz) gzclose($gz);
  }
  @unlink($raw);

  if(!$whole || !file_exists($file) || filesize($file) < 500){
    @unlink($file);
    logit('dump', 'mysqldump не справился: ' . ($why !== '' ? mb_substr($why, 0, 200)
          : (!$whole ? 'копия оборвалась на середине' : 'молчит, код ' . $code)));
    /* Запасной путь: копия средствами PHP — не зависит ни от версии mysqldump,
       ни от прав, ни от капризов файловой системы. Копия важнее способа. */
    $alt = psyDumpPhp($dir, $tables);
    if($alt){
      psyDumpKeep($dir);
      setSetting('dump_at', now());
      logit('dump', 'копия базы (запасным способом): ' . basename($alt) . ', ' . round(filesize($alt)/1024) . ' КБ');
      return [true, $alt];
    }
    return [false, $why !== '' ? $why : 'копия не получилась'];
  }

  psyDumpKeep($dir);
  setSetting('dump_at', now());
  logit('dump', 'копия базы: ' . basename($file) . ', ' . round(filesize($file)/1024) . ' КБ');
  return [true, $file];
}

/* держим две недели */
function psyDumpKeep($dir){
  foreach(glob($dir . '/psy_*.sql.gz') as $f)
    if(filemtime($f) < time() - 14*86400) @unlink($f);
}

/* Копия без mysqldump: структура и данные читаются через то же подключение,
   что и весь сайт, поэтому работает всегда, пока работает сам сайт. */
function psyDumpPhp($dir, $tables){
  $file = $dir . '/psy_' . date('Y-m-d_H-i') . '.sql.gz';
  $gz = gzopen($file, 'wb9');
  if(!$gz) return null;
  gzwrite($gz, "-- копия таблиц psy_* средствами PHP, " . now() . "\n" .
               "SET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS=0;\n\n");
  $pdo = db();
  foreach($tables as $t){
    $create = one("SHOW CREATE TABLE `" . str_replace('`', '', $t) . "`");
    $sql = $create ? array_values($create)[1] : '';
    gzwrite($gz, "DROP TABLE IF EXISTS `$t`;\n" . $sql . ";\n\n");
    $st = $pdo->query("SELECT * FROM `" . str_replace('`', '', $t) . "`");
    $n = 0;
    while($row = $st->fetch(PDO::FETCH_ASSOC)){
      $vals = [];
      foreach($row as $v) $vals[] = ($v === null) ? 'NULL' : $pdo->quote((string)$v);
      gzwrite($gz, "INSERT INTO `$t` VALUES(" . implode(',', $vals) . ");\n");
      $n++;
    }
    gzwrite($gz, "\n-- строк в `$t`: $n\n\n");
  }
  gzwrite($gz, "SET FOREIGN_KEY_CHECKS=1;\n");
  gzclose($gz);
  return (file_exists($file) && filesize($file) > 200) ? $file : null;
}

if(php_sapi_name() === 'cli' || (isset($_GET['key']) && hash_equals((string)cfg('relay_key'), (string)$_GET['key']))){
  list($ok, $res) = psyDump();
  echo ($ok ? "копия готова: $res" : "ошибка: $res"), PHP_EOL;
}
