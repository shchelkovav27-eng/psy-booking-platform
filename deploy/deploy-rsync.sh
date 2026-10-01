#!/usr/bin/env bash
# Выкладка одного из контуров (zapis | shkola | soprovod) на виртуальный хостинг по rsync.
# Трогает ТОЛЬКО папку своего контура: соседние сайты на том же хостинге не затрагиваются.
#
#   APP=shkola deploy/deploy-rsync.sh
#
# Параметры подключения — в deploy/deploy.env (см. deploy.env.example).
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
[ -f "$ROOT/deploy/deploy.env" ] && . "$ROOT/deploy/deploy.env"
APP="${APP:-shkola}"
case "$APP" in
  zapis)    LOCAL_DIR="$ROOT/apps/booking-aggregator/" ;;
  shkola)   LOCAL_DIR="$ROOT/apps/school-booking/" ;;
  soprovod) LOCAL_DIR="$ROOT/apps/onboarding-cabinet/" ;;
  *) echo "Неизвестный APP=$APP"; exit 1 ;;
esac
SSH_HOST="${DEPLOY_HOST:?задайте DEPLOY_HOST в deploy/deploy.env}"
REMOTE_ROOT="${REMOTE_ROOT:-public_html}"
REMOTE_DIR="$REMOTE_ROOT/$APP"
SSH="ssh -i ${SSH_KEY:-$HOME/.ssh/id_ed25519} -p ${SSH_PORT:-22} -o BatchMode=yes"

[ -d "$LOCAL_DIR" ] || { echo "Нет кода: $LOCAL_DIR"; exit 1; }

TS=$(date +%Y-%m-%d_%H-%M-%S)

echo "1) Бэкап текущей версии (если есть) -> ~/backups/${APP}_$TS"
$SSH "$SSH_HOST" "mkdir -p ~/backups; [ -d ~/$REMOTE_DIR ] && cp -a ~/$REMOTE_DIR ~/backups/${APP}_$TS || echo '   (первая заливка, бэкапить нечего)'"

echo "2) Ставлю версию на app.js/app.css, чтобы браузеры не показывали старое"
V=$(date +%s)
for f in "$LOCAL_DIR"index.html "$LOCAL_DIR"spec/index.html "$LOCAL_DIR"koordinator/index.html; do
  [ -f "$f" ] && sed -i '' -E "s/app\.(css|js)\?v=[0-9]+/app.\1?v=$V/g" "$f"
done

echo "3) Заливаю"
# Папку с загруженными фото выкладка не трогает: иначе каждый деплой
# стирал бы всё, что пользователи загрузили.
rsync -az --delete --exclude "foto" --exclude ".git*" -e "$SSH" "$LOCAL_DIR" "$SSH_HOST:~/$REMOTE_DIR/"

echo "4) Проверка синтаксиса PHP на сервере"
$SSH "$SSH_HOST" "cd ~/$REMOTE_DIR && for f in lib.php api.php cron.php; do php -l \$f; done"

echo "Готово: ${SITE_URL:-https://booking.example.com}/$APP/"
