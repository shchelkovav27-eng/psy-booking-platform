#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""
Реле Telegram для агрегатора записи.

Зачем: хостинг не пускает наружу к api.telegram.org, поэтому сервер
складывает сообщения в очередь, а это реле их забирает и доставляет. Обратно
оно приносит ответы людей (нажатия кнопок, тексты) — вся логика на сервере.

Запуск:
    python3 psy_relay.py --url https://ваш-сайт/zapis/api.php --key КЛЮЧ_РЕЛЕ

Ключ реле — во вкладке «Telegram» у координатора. Токен бота реле берёт
с сервера само, отдельно вписывать не нужно.

Останов — Ctrl+C. Пока реле не запущено, сообщения не теряются: они ждут
в очереди и уходят при следующем запуске.
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


def log(msg):
    print(time.strftime("[%H:%M:%S] ") + msg, flush=True)


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument("--url", required=True, help="адрес api.php на сервере")
    ap.add_argument("--key", required=True, help="ключ реле из вкладки «Telegram»")
    ap.add_argument("--pause", type=float, default=3.0, help="пауза между циклами, сек")
    args = ap.parse_args()

    relay_url = args.url + ("&" if "?" in args.url else "?") + "action=relay"
    bot_url = args.url + ("&" if "?" in args.url else "?") + "action=relay_bot"

    ack, failed, updates, offset = [], [], [], None
    known_bot = None
    quiet_since = 0

    log("Реле запущено. Сервер: %s" % args.url)
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
            if time.time() - quiet_since > 60:
                log("Токен бота ещё не задан. Вкладка «Telegram» → шаг 1.")
                quiet_since = time.time()
            time.sleep(10)
            continue

        # один раз сообщаем серверу адрес бота
        if known_bot is None:
            me = tg_call(token, "getMe", {}, timeout=20)
            if me.get("ok"):
                known_bot = me["result"].get("username", "")
                log("Бот на связи: @%s" % known_bot)
                if known_bot and known_bot != res.get("bot"):
                    try:
                        post_json(bot_url, {"key": args.key, "username": known_bot})
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
