"""Probe: are the signing rules read from OnlyFans' own web app code (release
202611031453-fbc506331f, by WebAppRulesExtractor) accepted, and does the x-of-rev
header matter? Four signed guest requests for madison420ivy: those rules and the
rafa-9 feed's, each with and without x-of-rev.

Run by hand on 4 Nov 2026; its output is in 15-live-probes.txt (section 3).
Python standard library only: python -I probe-webapp-rules.py
"""
import hashlib
import json
import secrets
import time
import urllib.error
import urllib.request

UA = "Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0.0.0 Safari/537.36"
REV = "202611031453-fbc506331f"
WEBAPP = {  # extracted by App\Upstream\OnlyFans\WebAppRulesExtractor from 2313.js of that release
    "static_param": "LVIMTEyGaoXC1Vp1q6i0QuwGciDSyaWw", "prefix": "67634", "suffix": "6ae9f59b",
    "indexes": [0, 0, 1, 1, 1, 1, 2, 2, 3, 3, 4, 4, 5, 8, 8, 9, 9, 10, 11, 12, 12, 13, 17, 17, 18, 20, 21, 33, 34, 34, 35, 37],
    "constant": -1634,
}
RAFA9 = {
    "static_param": "UQe4c0nznfr5xt8EANtG9HyHmvMdqNRX", "prefix": "64937", "suffix": "6aa15875",
    "indexes": [2, 5, 6, 7, 8, 9, 11, 14, 14, 14, 15, 15, 16, 18, 22, 23, 24, 24, 25, 25, 26, 27, 27, 32, 33, 33, 34, 35, 35, 35, 38, 39],
    "constant": 728,
}
APP_TOKEN = "33d57ade8c02dbc5a333db99ff9ae26a"


def sign(rules, path, ts, user_id="0"):
    sha = hashlib.sha1("\n".join([rules["static_param"], ts, path, user_id]).encode()).hexdigest()
    checksum = sum(ord(sha[i]) for i in rules["indexes"]) + rules["constant"]
    return f"{rules['prefix']}:{sha}:{abs(checksum):x}:{rules['suffix']}"


def call(rules, with_rev):
    path = "/api2/v2/users/madison420ivy"
    ts = str(int(time.time() * 1000))
    headers = {
        "accept": "application/json, text/plain, */*",
        "app-token": APP_TOKEN,
        "time": ts,
        "sign": sign(rules, path, ts),
        "x-bc": hashlib.sha1(secrets.token_bytes(32)).hexdigest(),
        "user-agent": UA,
    }
    if with_rev:
        headers["x-of-rev"] = REV
    req = urllib.request.Request("https://onlyfans.com" + path, headers=headers)
    try:
        with urllib.request.urlopen(req, timeout=20) as r:
            status, body, rev = r.status, r.read(), r.headers.get("x-of-rev")
    except urllib.error.HTTPError as e:
        status, body, rev = e.code, e.read(), e.headers.get("x-of-rev")
    try:
        data = json.loads(body)
        text = f"favoritedCount={data['favoritedCount']}" if status == 200 else json.dumps(data)[:120]
    except (ValueError, KeyError):
        text = f"{len(body)} bytes"
    return f"HTTP {status} | {text} | x-of-rev in response: {rev}"


for label, rules, with_rev in [
    ("A web app rules, with x-of-rev", WEBAPP, True),
    ("B web app rules, no x-of-rev  ", WEBAPP, False),
    ("C rafa-9 rules,  with x-of-rev", RAFA9, True),
    ("D rafa-9 rules,  no x-of-rev  ", RAFA9, False),
]:
    print(label, "->", call(rules, with_rev))
    time.sleep(1)
