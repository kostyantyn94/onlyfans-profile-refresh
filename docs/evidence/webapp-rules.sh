#!/bin/sh
# Signing rules read from OnlyFans' own web app, live. Downloads what a browser
# downloads (the page, app.js and the small script with the signing code; no API
# request, nothing signed, nothing executed) and reads the rules out of the
# obfuscated code (WebAppRulesExtractor). Then shows the public rule feeds at
# the same moment, for comparison. Writes docs/evidence/16-webapp-rules.txt.
set -e
# Git Bash on Windows would rewrite container paths; harmless elsewhere.
export MSYS_NO_PATHCONV=1
cd "$(dirname "$0")/../.."
run() { docker compose exec -T app php artisan "$@" --no-ansi; }

OUT=docs/evidence/16-webapp-rules.txt
LOG_OFFSET=$(wc -c < storage/logs/refresh.log 2>/dev/null || echo 0)
# Rules are kept per release; forget them so the code is read again in this run.
for key in $(docker compose exec -T redis redis-cli -n 1 --scan --pattern '*onlyfans:webapp-rules*' | tr -d '\r'); do
  docker compose exec -T redis redis-cli -n 1 del "$key" >/dev/null
done
{
  echo "# Signing rules read from OnlyFans' web app code, live."
  echo "# Commit: $(git log -1 --format='%h %s')   Run: $(date -u +%Y-%m-%dT%H:%MZ)"
  echo
  echo '$ php artisan onlyfans:rules --reload     # reads the page, app.js and the signing script'
  run onlyfans:rules --reload
  echo
  echo '$ php artisan onlyfans:rules --reload     # same release: only the page is read again'
  run onlyfans:rules --reload | grep -E 'source|release|fingerprint'
  echo
  echo '# storage/logs/refresh.log during this run:'
  tail -c +$((LOG_OFFSET + 1)) storage/logs/refresh.log | grep -E 'onlyfans\.(rules_|webapp_)' | cut -c1-400
  echo
  echo '# The public rule feeds right now (not used by default; opt-in via ONLYFANS_RULES_SOURCES), for comparison:'
  for url in \
    https://raw.githubusercontent.com/rafa-9/dynamic-rules/main/rules.json \
    https://raw.githubusercontent.com/DATAHOARDERS/dynamic-rules/main/onlyfans.json \
    https://raw.githubusercontent.com/datawhores/onlyfans-dynamic-rules/main/dynamicRules.json \
    https://raw.githubusercontent.com/riley-access-labs/onlyfans-dynamic-rules-1/main/dynamicRules.json; do
    echo "$url"
    curl -fsS --max-time 10 "$url" | tr -d '\n ' | grep -oE '"(static_param|prefix|start|suffix|end|format|checksum_constant|revision)":("[^"]*"|[^,}"]*)' | tr '\n' ' '
    echo
  done
} > "$OUT" 2>&1

cat "$OUT"
