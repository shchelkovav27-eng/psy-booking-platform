#!/usr/bin/env bash
# Поставить реле Telegram (для агрегатора, школы или сопровождения) — один раз, дальше работает само.
#
# Что делает: прописывает реле в автозапуск компьютера (launchd). После
# этого оно включается вместе с Маком и держится само: закрыли терминал —
# работает, перезагрузились — работает.
#
# Ключ реле берём с сервера сами, вписывать руками ничего не нужно.
#
# Запуск:   APP=shkola relay/install-relay-launchd.sh
# Убрать:   APP=shkola relay/install-relay-launchd.sh --ubrat
# Как дела: APP=shkola relay/install-relay-launchd.sh --kak-dela
set -euo pipefail

# Параметры — из deploy/deploy.env (см. deploy/deploy.env.example), в репозиторий не коммитится.
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
[ -f "$ROOT/deploy/deploy.env" ] && . "$ROOT/deploy/deploy.env"
APP="${APP:-shkola}"                       # zapis | shkola | soprovod
METKA="${RELAY_LABEL:-com.example.relay.$APP}"
HOST="${DEPLOY_HOST:?задайте DEPLOY_HOST в deploy/deploy.env}"
SSH="ssh -i ${SSH_KEY:-$HOME/.ssh/id_ed25519} -p ${SSH_PORT:-22} -o BatchMode=yes"
SAIT="${SITE_URL:?задайте SITE_URL}/$APP/api.php"
REMOTE_ROOT="${REMOTE_ROOT:-public_html}"
DOM="$ROOT/relay"
case "$APP" in
  zapis)    SKRIPT="$DOM/psy_relay.py" ;;
  soprovod) SKRIPT="$DOM/onboarding_relay.py" ;;
  *)        SKRIPT="$DOM/school_relay.py" ;;
esac
PLIST="$HOME/Library/LaunchAgents/$METKA.plist"
ZHURNAL="$DOM/relay-$APP.log"

# launchctl list в конвейере с grep -q падает по SIGPIPE, а pipefail
# превращает это в «не нашли». Поэтому сперва забираем список целиком.
najti(){ launchctl list > /tmp/lc.$$ 2>/dev/null || true; grep "$METKA" /tmp/lc.$$ || true; rm -f /tmp/lc.$$; }

kak_dela(){
  echo "Реле школы ($METKA)"
  stroka=$(najti)
  if [ -n "$stroka" ]; then
    echo "  автозапуск: включён"
    echo "$stroka" | awk '{print "  состояние: код выхода "$2", pid "$1}'
  else
    echo "  автозапуск: выключен"
  fi
  if [ -f "$ZHURNAL" ]; then
    echo "  последние строки журнала:"
    tail -n 8 "$ZHURNAL" | sed 's/^/    /'
  else
    echo "  журнала пока нет — реле ещё не работало"
  fi
}

ubrat(){
  launchctl unload "$PLIST" 2>/dev/null || true
  rm -f "$PLIST"
  echo "Реле убрано из автозапуска. Сообщения будут ждать в очереди на сервере."
  echo "Напоминания на почту это не затрагивает — их шлёт планировщик на сервере."
}

case "${1:-}" in
  --ubrat)    ubrat; exit 0 ;;
  --kak-dela) kak_dela; exit 0 ;;
esac

[ -f "$SKRIPT" ] || { echo "Не нашла $SKRIPT"; exit 1; }

echo "1) Спрашиваю у сервера ключ реле"
KLYUCH=$($SSH "$HOST" "cd ~/$REMOTE_ROOT/$APP && php -r 'require_once \"lib.php\"; initTables(); settings(); echo cfg(\"relay_key\");'")
[ -n "$KLYUCH" ] || { echo "Сервер не отдал ключ реле. Откройте страницу «Telegram» — он должен там быть."; exit 1; }
echo "   ключ получен"

echo "2) Прописываю в автозапуск"
mkdir -p "$HOME/Library/LaunchAgents"
launchctl unload "$PLIST" 2>/dev/null || true
cat > "$PLIST" <<PLISTEOF
<?xml version="1.0" encoding="UTF-8"?>
<!DOCTYPE plist PUBLIC "-//Apple//DTD PLIST 1.0//EN" "http://www.apple.com/DTDs/PropertyList-1.0.dtd">
<plist version="1.0">
<dict>
  <key>Label</key><string>$METKA</string>
  <key>ProgramArguments</key>
  <array>
    <string>/usr/bin/python3</string>
    <string>$SKRIPT</string>
    <string>--url</string><string>$SAIT</string>
    <string>--key</string><string>$KLYUCH</string>
  </array>
  <key>RunAtLoad</key><true/>
  <key>KeepAlive</key><true/>
  <key>StandardOutPath</key><string>$ZHURNAL</string>
  <key>StandardErrorPath</key><string>$ZHURNAL</string>
</dict>
</plist>
PLISTEOF
chmod 600 "$PLIST"
launchctl load "$PLIST"

echo "3) Проверяю"
podnyalos=0
for i in 1 2 3 4 5; do
  sleep 2
  if [ -n "$(najti)" ]; then podnyalos=1; break; fi
done
if [ "$podnyalos" = "1" ]; then
  echo "   реле поднялось"
else
  echo "   не поднялось — посмотрите $ZHURNAL"
  exit 1
fi

echo
tail -n 6 "$ZHURNAL" 2>/dev/null | sed 's/^/   /' || true
echo
echo "Готово. Реле работает и будет включаться вместе с компьютером."
echo "Проверить в любой момент:  APP=$APP relay/install-relay-launchd.sh --kak-dela"
