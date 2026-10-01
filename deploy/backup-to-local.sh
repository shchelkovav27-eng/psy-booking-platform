#!/usr/bin/env bash
# Резервная копия внутреннего контура школы — к себе на компьютер.
#
# Зачем: сайт живёт на чужом сервере, рядом с чужим проектом. Если там
# что-то снесут или про нас забудут, у нас должна быть своя копия, которой
# ни у кого нет доступа удалить. Копируем и данные (таблицы shk_*), и код.
#
# Запуск: APP=shkola deploy/backup-to-local.sh  (скрипт написан для контура школы, таблицы shk_*)
# Копии складываются в backups-local/<app>/ГГГГ-ММ-ДД_ЧЧ-ММ/ (папка в .gitignore)
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
[ -f "$ROOT/deploy/deploy.env" ] && . "$ROOT/deploy/deploy.env"
APP="${APP:-shkola}"
REMOTE_ROOT="${REMOTE_ROOT:-public_html}"
HOST="${DEPLOY_HOST:?задайте DEPLOY_HOST в deploy/deploy.env}"
KEY="${SSH_KEY:-$HOME/.ssh/id_ed25519}"
PORT="${SSH_PORT:-22}"
SSH="ssh -i $KEY -p $PORT -o BatchMode=yes"
TS=$(date +%Y-%m-%d_%H-%M)
OUT="$ROOT/backups-local/$APP/$TS"

mkdir -p "$OUT"

echo "1) Выгружаю таблицы shk_* с сервера"
# Доступы к базе лежат на сервере в config.php и наружу не выносятся:
# запрос на выгрузку собираем прямо там.
$SSH "$HOST" "cd ~/$REMOTE_ROOT/$APP && "'php -r "
  require_once __DIR__ . \"/../config.php\";
  \$pdo = new PDO(\"mysql:host=\".DB_HOST.\";dbname=\".DB_NAME.\";charset=utf8mb4\", DB_USER, DB_PASS);
  \$t = [];
  foreach(\$pdo->query(\"SHOW TABLES LIKE \\\"shk_%\\\"\") as \$r) \$t[] = \$r[0];
  file_put_contents(\"/tmp/shk_tables.txt\", implode(\" \", \$t));
  file_put_contents(\"/tmp/shk_my.cnf\", \"[client]\nuser=\".DB_USER.\"\npassword=\\\"\".DB_PASS.\"\\\"\nhost=\".DB_HOST.\"\n\");
  chmod(\"/tmp/shk_my.cnf\", 0600);
  echo DB_NAME;
" > /tmp/shk_db.txt && mysqldump --defaults-extra-file=/tmp/shk_my.cnf \
     --single-transaction --no-tablespaces \
     "$(cat /tmp/shk_db.txt)" $(cat /tmp/shk_tables.txt) > /tmp/shkola-dump.sql \
   && rm -f /tmp/shk_my.cnf /tmp/shk_tables.txt /tmp/shk_db.txt \
   && gzip -f /tmp/shkola-dump.sql && echo "   выгружено"'

echo "2) Забираю дамп к себе"
scp -i "$KEY" -P "$PORT" -o BatchMode=yes \
    "$HOST:/tmp/shkola-dump.sql.gz" "$OUT/dannye.sql.gz" >/dev/null
$SSH "$HOST" "rm -f /tmp/shkola-dump.sql.gz"

echo "3) Забираю код с сервера"
rsync -az -e "$SSH" "$HOST:~/$REMOTE_ROOT/$APP/" "$OUT/kod/"

echo "4) Что внутри"
{
  echo "Копия внутреннего контура школы — $TS"
  echo
  echo "dannye.sql.gz — все таблицы shk_* (записи, ученицы, слоты, ссылки, очередь, настройки)."
  echo "kod/          — код сайта, как он лежал на сервере."
  echo
  echo "Как развернуть на другом хостинге:"
  echo "  1) распаковать dannye.sql.gz и залить в новую базу;"
  echo "  2) положить kod/ в папку сайта, рядом — config.php с доступами к базе;"
  echo "  3) Telegram: с хостинга он недоступен, доставку делает реле на компьютере."
} > "$OUT/ПРОЧТИ.txt"

du -sh "$OUT" | sed 's/^/   /'
echo
echo "Готово: $OUT"
