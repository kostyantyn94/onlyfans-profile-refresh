#!/bin/sh
# Re-creates the evidence files in docs/evidence. Needs the stack running
# (docker compose up -d). Takes about six minutes: the two crash tests each
# wait for retry_after (90 s).
set -e
cd "$(dirname "$0")/../.."
run() { docker compose exec -T app php artisan "$@" --no-ansi; }

SINCE=$(date -u +"%Y-%m-%d %H:%M:%S")
run workload:run --handler=legacy > docs/evidence/03-workload-before-fix-legacy.txt 2>&1
# Recovery: repair what the old handler wrote, with the fixed handler.
{ echo "$ php artisan profiles:repair --since=\"$SINCE\""; run profiles:repair --since="$SINCE"; echo; echo "$ php artisan workload:check"; run workload:check; } > docs/evidence/03b-repair-after-legacy.txt 2>&1
run workload:run --handler=v2 > docs/evidence/04-workload-after-fix-v2.txt 2>&1
run workload:crash --at=after-write > docs/evidence/05-crash-after-write.txt 2>&1
run workload:crash --at=before-write > docs/evidence/06-crash-before-write.txt 2>&1
run workload:throughput --profiles=3000 > docs/evidence/07-throughput.txt 2>&1
run test --colors=never > docs/evidence/08-full-test-suite.txt 2>&1
cp "$(ls -t storage/app/workload/legacy-*.json | head -1)" docs/evidence/03-workload-before-fix-legacy.json
cp "$(ls -t storage/app/workload/v2-*.json | head -1)" docs/evidence/04-workload-after-fix-v2.json
echo "evidence written to docs/evidence"
