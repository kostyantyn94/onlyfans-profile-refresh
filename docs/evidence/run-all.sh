#!/bin/sh
# Re-creates the evidence files in docs/evidence, in one pass. Needs the stack
# running (docker compose up -d). Takes about seven minutes: the two crash
# tests each wait for retry_after (90 s).
#
# 01 is not re-created: it was recorded at commit 83496a9, when only the old
# handler existed. 01b shows the same tests failing against the old handler
# at the current commit.
set -e
# Git Bash on Windows would rewrite container paths like /app/vendor; harmless elsewhere.
export MSYS_NO_PATHCONV=1
cd "$(dirname "$0")/../.."
run() { docker compose exec -T app php artisan "$@" --no-ansi; }

{ echo "# REFRESH_HANDLER=legacy php artisan test --group=incident (old handler, current commit)"; echo; docker compose exec -T -e REFRESH_HANDLER=legacy app php artisan test --group=incident --colors=never || true; } > docs/evidence/01b-incident-tests-legacy-now.txt 2>&1
{ echo "# php artisan test --group=incident (fixed handler)"; echo; run test --group=incident --colors=never; } > docs/evidence/02-incident-tests-after-fix.txt 2>&1

# The scheduler container would claim workload rows too; keep it out of the measurements.
docker compose stop scheduler

SINCE=$(date -u +"%Y-%m-%d %H:%M:%S")
run workload:run --handler=legacy > docs/evidence/03-workload-before-fix-legacy.txt 2>&1
# Recovery: repair what the old handler wrote, with the fixed handler.
{ echo "\$ php artisan profiles:repair --since=\"$SINCE\""; run profiles:repair --since="$SINCE"; echo; echo "\$ php artisan workload:check"; run workload:check; } > docs/evidence/03b-repair-after-legacy.txt 2>&1
cp "$(ls -t storage/app/workload/legacy-*.json | head -1)" docs/evidence/03-workload-before-fix-legacy.json

run workload:run --handler=v2 > docs/evidence/04-workload-after-fix-v2.txt 2>&1
cp "$(ls -t storage/app/workload/v2-*.json | head -1)" docs/evidence/04-workload-after-fix-v2.json

run workload:crash --at=after-write > docs/evidence/05-crash-after-write.txt 2>&1
run workload:crash --at=before-write > docs/evidence/06-crash-before-write.txt 2>&1
run workload:throughput --profiles=3000 > docs/evidence/07-throughput.txt 2>&1
run workload:scheduler-memory --profiles=30000 > docs/evidence/09-scheduler-memory.txt 2>&1

docker compose start scheduler
run test --colors=never > docs/evidence/08-full-test-suite.txt 2>&1
echo "evidence written to docs/evidence"
