#!/bin/sh
# Re-creates the evidence files in docs/evidence, in one pass. Needs the stack
# running (docker compose up -d). The two crash tests each wait for
# retry_after (90 s); the whole run takes about the time it prints at the end.
# Every text file starts with the commit and time it was recorded at.
#
# 01 is not re-created: it was recorded at commit 83496a9, when only the old
# handler existed. 01b shows the same tests failing against the old handler
# at the current commit. 12, 14, 15 and 16 are recorded separately (see each).
set -e
# Git Bash on Windows would rewrite container paths like /app/vendor; harmless elsewhere.
export MSYS_NO_PATHCONV=1
cd "$(dirname "$0")/../.."
run() { docker compose exec -T app php artisan "$@" --no-ansi; }
stamp() { echo "# Commit $(git log -1 --format='%h') ($(git log -1 --format='%s')), recorded $(date -u +%Y-%m-%dT%H:%MZ)"; }
STARTED=$(date +%s)

# Workers and the web server run the code of this commit.
docker compose restart horizon >/dev/null

{ stamp; echo "# REFRESH_HANDLER=legacy php artisan test --group=incident (old handler, current commit)"; echo; docker compose exec -T -e REFRESH_HANDLER=legacy app php artisan test --group=incident --colors=never || true; } > docs/evidence/01b-incident-tests-legacy-now.txt 2>&1
{ stamp; echo "# php artisan test --group=incident (fixed handler)"; echo; run test --group=incident --colors=never; } > docs/evidence/02-incident-tests-after-fix.txt 2>&1

# The scheduler container would claim workload rows too; keep it out of the measurements
# (and start it again however this script ends). Run nothing else that starts it meanwhile.
docker compose stop scheduler
trap 'docker compose start scheduler >/dev/null 2>&1' EXIT

SINCE=$(date -u +"%Y-%m-%d %H:%M:%S")
{ stamp; run workload:run --handler=legacy; } > docs/evidence/03-workload-before-fix-legacy.txt 2>&1
# Recovery: repair what the old handler wrote, with the fixed handler.
{ stamp; echo "\$ php artisan profiles:repair --since=\"$SINCE\""; run profiles:repair --since="$SINCE"; echo; echo "\$ php artisan workload:check"; run workload:check; } > docs/evidence/03b-repair-after-legacy.txt 2>&1
cp "$(ls -t storage/app/workload/legacy-*.json | head -1)" docs/evidence/03-workload-before-fix-legacy.json

{ stamp; run workload:run --handler=v2; } > docs/evidence/04-workload-after-fix-v2.txt 2>&1
cp "$(ls -t storage/app/workload/v2-*.json | head -1)" docs/evidence/04-workload-after-fix-v2.json

# The fake upstream switches to new signing rules 4 s in, as OnlyFans does with a release.
OFFSET=$(wc -c < storage/logs/refresh.log)
{ stamp; echo "\$ php artisan workload:run --handler=v2 --rotate-rules=4"; run workload:run --handler=v2 --rotate-rules=4; echo; echo "# signing events in storage/logs/refresh.log during the run:"; tail -c +$((OFFSET + 1)) storage/logs/refresh.log | grep '"onlyfans\.'; } > docs/evidence/13-workload-rules-rotation.txt 2>&1
cp "$(ls -t storage/app/workload/v2-*.json | head -1)" docs/evidence/13-workload-rules-rotation.json

{ stamp; run workload:crash --at=after-write; } > docs/evidence/05-crash-after-write.txt 2>&1
{ stamp; run workload:crash --at=before-write; } > docs/evidence/06-crash-before-write.txt 2>&1
{ stamp; run workload:throughput --profiles=3000; } > docs/evidence/07-throughput.txt 2>&1
{ stamp; run workload:scheduler-memory --profiles=30000; } > docs/evidence/09-scheduler-memory.txt 2>&1

docker compose start scheduler
{ stamp; run test --colors=never; } > docs/evidence/08-full-test-suite.txt 2>&1
echo "evidence written to docs/evidence in $(( ($(date +%s) - STARTED) / 60 )) min $(( ($(date +%s) - STARTED) % 60 )) s"
