"""Probe: does OnlyFans still accept rules read from an earlier web app release?
That decides how far resources/onlyfans/last-accepted-rules.json carries a setup
whose web app code cannot be read.

Two signed guest requests for madison420ivy, each from a new guest session and
with the headers OnlyFansClient sends: one with the bundled rules (the current
release when they were saved), one with the rules of the release before it.
Writes 17-previous-release-rules.txt next to this script.

Run by hand from the project root: python -I docs/evidence/probe-previous-rules.py
"""
import hashlib
import http.cookiejar
import json
import pathlib
import secrets
import subprocess
import time
import urllib.error
import urllib.request

HERE = pathlib.Path(__file__).parent
ROOT = HERE.parent.parent
UA = "Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/149.0.0.0 Safari/537.36"
OUT = HERE / "17-previous-release-rules.txt"

# Read from OnlyFans' web app on 4 Nov (docs/evidence/16 at 14e3fa1), accepted that day.
RELEASE_3_NOV = {
    "release": "202611031453-fbc506331f",
    "static_param": "LVIMTEyGaoXC1Vp1q6i0QuwGciDSyaWw",
    "prefix": "67634",
    "suffix": "6ae9f59b",
    "checksum_indexes": [0, 0, 1, 1, 1, 1, 2, 2, 3, 3, 4, 4, 5, 8, 8, 9, 9, 10, 11, 12, 12, 13, 17, 17, 18, 20, 21, 33, 34, 34, 35, 37],
    "checksum_constant": -1634,
    "app_token": "33d57ade8c02dbc5a333db99ff9ae26a",
}


def sign(rules, path, ts, user_id):
    sha = hashlib.sha1("\n".join([rules["static_param"], ts, path, user_id]).encode()).hexdigest()
    checksum = sum(ord(sha[i]) for i in rules["checksum_indexes"]) + rules["checksum_constant"]
    return f"{rules['prefix']}:{sha}:{abs(checksum):x}:{rules['suffix']}"


def call(rules, username="madison420ivy"):
    path = f"/api2/v2/users/{username}"
    ts = str(int(time.time() * 1000))
    headers = {
        "accept": "application/json, text/plain, */*",
        "app-token": rules["app_token"],
        "sign": sign(rules, path, ts, "0"),
        "time": ts,
        "x-bc": hashlib.sha1(secrets.token_bytes(32)).hexdigest(),
        "user-id": "0",
        "x-of-rev": rules["release"],
        "user-agent": UA,
    }
    opener = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()))
    started = time.monotonic()
    try:
        with opener.open(urllib.request.Request("https://onlyfans.com" + path, headers=headers), timeout=20) as r:
            status, body, rev = r.status, r.read(), r.headers.get("x-of-rev")
    except urllib.error.HTTPError as e:
        status, body, rev = e.code, e.read(), e.headers.get("x-of-rev")
    ms = int((time.monotonic() - started) * 1000)
    try:
        data = json.loads(body)
        text = (f"favoritedCount={data['favoritedCount']} fields={len(data)}"
                if status == 200 and "favoritedCount" in data else json.dumps(data)[:120])
    except ValueError:
        text = f"non-JSON {len(body)} bytes"
    return f"HTTP {status} in {ms} ms | {text} | OnlyFans' current release (x-of-rev): {rev}"


def main():
    bundled = json.loads((ROOT / "resources/onlyfans/last-accepted-rules.json").read_text(encoding="utf-8"))
    commit = subprocess.run(["git", "log", "-1", "--format=%h %s"], cwd=ROOT, capture_output=True, text=True).stdout.strip()
    lines = [
        "# Live: are rules from an earlier OnlyFans release still accepted?",
        f"# Commit: {commit}   Run: {time.strftime('%Y-%m-%dT%H:%MZ', time.gmtime())}",
        "# Two signed guest requests to onlyfans.com/api2/v2/users/madison420ivy, each from a new guest session.",
        "",
    ]
    for label, rules in [
        (f"bundled rules, release {bundled['release']}", bundled),
        (f"rules of the release before, {RELEASE_3_NOV['release']}", RELEASE_3_NOV),
    ]:
        lines.append(f"{label}: {call(rules)}")
        time.sleep(2)
    text = "\n".join(lines) + "\n"
    OUT.write_text(text, encoding="utf-8", newline="\n")
    print(text, end="")


if __name__ == "__main__":
    main()
