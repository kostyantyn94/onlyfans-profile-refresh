"""Probe: which headers and cookies a signed guest request really needs, and
what OnlyFans answers for an unknown user and for a wrong signature. Saves the
successful profile body (public profile data) next to this script; that body is
resources/fixtures/upstream/onlyfans-madison420ivy.json.

Run by hand on 4 Nov 2026 (6 requests to onlyfans.com); its output is in
15-live-probes.txt. Python standard library only: python -I probe-onlyfans.py
"""
import hashlib
import http.cookiejar
import json
import pathlib
import secrets
import time
import urllib.error
import urllib.request

RULES_URL = "https://raw.githubusercontent.com/rafa-9/dynamic-rules/main/rules.json"
UA = "Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0.0.0 Safari/537.36"
HERE = pathlib.Path(__file__).parent


def load_rules():
    with urllib.request.urlopen(RULES_URL, timeout=15) as r:
        raw = json.loads(r.read())
    return {
        "static_param": raw["static_param"],
        "prefix": raw.get("prefix") or raw["format"].split(":")[0],
        "suffix": raw.get("suffix") or raw["format"].split(":")[-1],
        "indexes": raw["checksum_indexes"],
        "constant": raw["checksum_constant"],
        "app_token": raw.get("app_token") or raw.get("app-token"),
    }


def sign(rules, path, ts, user_id):
    sha = hashlib.sha1("\n".join([rules["static_param"], ts, path, user_id]).encode()).hexdigest()
    checksum = sum(ord(sha[i]) for i in rules["indexes"]) + rules["constant"]
    return f"{rules['prefix']}:{sha}:{abs(checksum):x}:{rules['suffix']}"


def new_opener():
    jar = http.cookiejar.CookieJar()
    return urllib.request.build_opener(urllib.request.HTTPCookieProcessor(jar)), jar


def call(opener, rules, username, x_bc=True, user_id_header=True):
    path = f"/api2/v2/users/{username}"
    ts = str(int(time.time() * 1000))
    headers = {
        "accept": "application/json, text/plain, */*",
        "app-token": rules["app_token"],
        "time": ts,
        "sign": sign(rules, path, ts, "0"),
        "user-agent": UA,
    }
    if x_bc:
        headers["x-bc"] = hashlib.sha1(secrets.token_bytes(32)).hexdigest()
    if user_id_header:
        headers["user-id"] = "0"
    req = urllib.request.Request("https://onlyfans.com" + path, headers=headers)
    try:
        with opener.open(req, timeout=20) as r:
            return r.status, r.read(), r.headers
    except urllib.error.HTTPError as e:
        return e.code, e.read(), e.headers


def show(label, status, body, headers):
    try:
        data = json.loads(body)
        text = (f"username={data.get('username')} favoritedCount={data.get('favoritedCount')} fields={len(data)}"
                if status == 200 and "favoritedCount" in data else json.dumps(data)[:160])
    except ValueError:
        text = f"non-JSON {len(body)} bytes: {body[:80]!r}"
    cookies = sorted({v.split("=", 1)[0] for k, v in headers.items() if k.lower() == "set-cookie"})
    print(f"{label}: HTTP {status} | {text} | sets cookies: {cookies}")


def main():
    rules = load_rules()

    opener, jar = new_opener()
    status, body, headers = call(opener, rules, "madison420ivy")
    show("1 no cookies at all", status, body, headers)
    if status == 200:
        (HERE / "onlyfans-madison420ivy.json").write_bytes(body)
        names = sorted(k.lower() for k in headers.keys() if k.lower() != "set-cookie")
        print("   response header names:", names)
    print("   cookies now:", sorted(c.name for c in jar))
    time.sleep(1)

    show("2 same session again", *call(opener, rules, "madison420ivy"))
    time.sleep(1)
    show("3 no x-bc header", *call(new_opener()[0], rules, "madison420ivy", x_bc=False))
    time.sleep(1)
    show("4 no user-id header", *call(new_opener()[0], rules, "madison420ivy", user_id_header=False))
    time.sleep(1)
    show("5 unknown username", *call(opener, rules, "nosuchuser9f8e7d6c5b"))
    time.sleep(1)
    bad = dict(rules, static_param="x" * 32)
    show("6 wrong signing rules", *call(opener, bad, "madison420ivy"))


if __name__ == "__main__":
    main()
