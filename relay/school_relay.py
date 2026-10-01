#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""
Реле Telegram для внутреннего контура школы.

Зачем оно нужно: хостинг не пускает наружу к api.telegram.org.
Поэтому сервер складывает сообщения в очередь, а это реле забирает их с
вашего компьютера и доставляет. Обратно приносит ответы людей — нажатия
кнопок и тексты. Вся логика и все данные остаются на сервере, реле —
только транспорт.

Запуск руками (для проверки):
    python3 relay/school_relay.py --url https://booking.example.com/shkola/api.php --key КЛЮЧ

Обычно его ставят один раз через relay/install-relay-launchd.sh, и дальше оно работает
само, включаясь вместе с компьютером.

Ключ реле — на странице «Telegram» у владельца. Токен бота реле берёт
с сервера само, отдельно вписывать не нужно.

Пока реле не запущено, сообщения не теряются: они ждут в очереди и уходят
при следующем запуске. Напоминания на почту при этом идут своим ходом —
их отправляет планировщик на сервере, реле для них не нужно.

Нужен только Python 3 — никаких библиотек ставить не надо.
"""

import argparse
import json
import ssl
import sys
import time
import urllib.error
import urllib.parse
import urllib.request

TG = "https://api.telegram.org/bot{token}/{method}"
CTX = ssl.create_default_context()


def post_json(url, payload, timeout=40):
    data = json.dumps(payload, ensure_ascii=False).encode("utf-8")
    req = urllib.request.Request(
        url, data=data, headers={"Content-Type": "application/json"}, method="POST"
    )
    with urllib.request.urlopen(req, timeout=timeout, context=CTX) as r:
        return json.loads(r.read().decode("utf-8", "replace"))


def tg_call(token, method, payload, timeout=40):
    url = TG.format(token=token, method=method)
    try:
        return post_json(url, payload, timeout)
    except urllib.error.HTTPError as e:
        body = e.read().decode("utf-8", "replace")
        try:
            return json.loads(body)
        except Exception:
            return {"ok": False, "description": "HTTP %s: %s" % (e.code, body[:200])}
    except Exception as e:
        return {"ok": False, "description": str(e)}


def log(msg):
    print(time.strftime("[%d.%m %H:%M:%S] ") + msg, flush=True)


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument("--url", required=True, help="адрес api.php школы")
    ap.add_argument("--key", required=True, help="ключ реле со страницы «Telegram»")
    ap.add_argument("--pause", type=float, default=3.0, help="пауза между циклами, сек")
    args = ap.parse_args()

    relay_url = args.url + ("&" if "?" in args.url else "?") + "action=relay"
    bot_url = args.url + ("&" if "?" in args.url else "?") + "action=relay_bot"

    ack, failed, updates, offset = [], [], [], None
    known_bot = None
    molchim_s = 0

    log("Реле школы запущено. Сервер: %s" % args.url)
    log("Останов — Ctrl+C. Закроете окно — сообщения подождут в очереди.")

    while True:
        try:
            res = post_json(relay_url, {
                "key": args.key,
                "ack": ack,
                "failed": failed,
                "updates": updates,
                "offset": offset,
            })
        except Exception as e:
            log("Сервер недоступен (%s). Повтор через 10 с." % e)
            time.sleep(10)
            continue

        if not res.get("ok"):
            log("Сервер отказал: %s" % res.get("error"))
            time.sleep(15)
            continue

        ack, failed, updates = [], [], []
        token = (res.get("token") or "").strip()
        if not token:
            # Токен вписывает владелец на странице «Telegram». Пока его нет,
            # тихо ждём: ругаться раз в минуту достаточно.
            if time.time() - molchim_s > 60:
                log("Токен бота ещё не задан. Страница «Telegram» → шаг 1.")
                molchim_s = time.time()
            time.sleep(10)
            continue

        # один раз узнаём адрес бота и сообщаем его серверу
        if known_bot is None:
            me = tg_call(token, "getMe", {}, timeout=20)
            if me.get("ok"):
                known_bot = me["result"].get("username", "")
                log("Бот на связи: @%s" % known_bot)
                if known_bot and known_bot != res.get("bot"):
                    try:
                        post_json(bot_url, {"key": args.key, "username": known_bot})
                        log("Записала адрес бота на сервер.")
                    except Exception:
                        pass
            else:
                log("Токен не принят Telegram: %s" % me.get("description"))
                time.sleep(20)
                continue

        # 1) отдаём накопившиеся сообщения
        for m in res.get("messages", []):
            payload = {
                "chat_id": m["chat"],
                "text": m["text"],
                "disable_web_page_preview": True,
            }
            if m.get("kb"):
                payload["reply_markup"] = m["kb"]
            r = tg_call(token, "sendMessage", payload)
            if r.get("ok"):
                ack.append(m["id"])
            else:
                failed.append({"id": m["id"], "err": str(r.get("description"))[:200]})
                log("Не доставлено #%s: %s" % (m["id"], r.get("description")))
        if ack:
            log("Отправлено сообщений: %d" % len(ack))

        # 2) забираем входящие
        off = res.get("offset") or 0
        upd = tg_call(token, "getUpdates", {
            "offset": off,
            "timeout": 5,
            "allowed_updates": ["message", "callback_query"],
        }, timeout=40)
        if upd.get("ok"):
            got = upd.get("result", [])
            for u in got:
                offset = u["update_id"] + 1
                if "callback_query" in u:
                    tg_call(token, "answerCallbackQuery",
                            {"callback_query_id": u["callback_query"]["id"]}, timeout=15)
                updates.append(u)
            if got:
                log("Получено ответов: %d" % len(got))
        else:
            log("getUpdates: %s" % upd.get("description"))

        if not res.get("messages") and not updates:
            time.sleep(args.pause)


if __name__ == "__main__":
    try:
        main()
    except KeyboardInterrupt:
        print("\nРеле остановлено. Очередь на сервере не потеряна.")
        sys.exit(0)
