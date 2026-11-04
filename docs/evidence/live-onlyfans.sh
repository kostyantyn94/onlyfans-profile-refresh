#!/bin/sh
# Live check against onlyfans.com, through the full refresh path: seeds the
# "onlyfans" account (guest session) with madison420ivy, fetches it in this
# process, then once more through Horizon, and records what was stored.
# Writes docs/evidence/14-live-onlyfans-fetch.txt. Two profile requests to
# onlyfans.com, plus the signing rules from the first source that works.
#
# After this the scheduler keeps the profile fresh on its own (every 24 h).
set -e
# Git Bash on Windows would rewrite container paths like /app/vendor; harmless elsewhere.
export MSYS_NO_PATHCONV=1
cd "$(dirname "$0")/../.."
run() { docker compose exec -T app php artisan "$@" --no-ansi; }
sql() { docker compose exec -T postgres psql -U app -d profiles -c "$1"; }

OUT=docs/evidence/14-live-onlyfans-fetch.txt
LOG_OFFSET=$(wc -c < storage/logs/refresh.log 2>/dev/null || echo 0)
{
  echo "# Live: signed guest requests to onlyfans.com/api2/v2/users/madison420ivy."
  echo "# Commit: $(git log -1 --format='%h %s')   Run: $(date -u +%Y-%m-%dT%H:%MZ)"
  echo
  echo '$ php artisan db:seed --force'
  run db:seed --force
  echo
  echo '$ php artisan profiles:fetch madison420ivy          # first attempt in this process'
  run profiles:fetch madison420ivy || true
  echo
  echo '$ php artisan profiles:fetch madison420ivy --queue  # the same through Horizon'
  run profiles:fetch madison420ivy --queue
  sleep 8
  echo
  echo '# refresh_attempts of the onlyfans account (newest first):'
  sql "select a.created_at, a.handler, a.outcome, a.http_status, a.likes, a.duration_ms
         from refresh_attempts a join accounts c on c.id = a.account_id
        where c.slug = 'onlyfans' order by a.id desc limit 5"
  echo '# stored profile:'
  sql "select p.username, p.upstream_id, p.name, p.likes_count, p.posts_count, p.photos_count, p.videos_count,
              (select count(*) from jsonb_object_keys(p.raw)) as raw_fields, p.last_attempt_status, p.next_refresh_at
         from profiles p join accounts c on c.id = p.account_id
        where c.slug = 'onlyfans' and p.username = 'madison420ivy'"
  echo '# signing and refresh events in storage/logs/refresh.log during this run (secrets are never logged):'
  tail -c +$((LOG_OFFSET + 1)) storage/logs/refresh.log | grep -E '"(onlyfans\.|refresh\.(success|unchanged|failed|retry_scheduled))' | cut -c1-400
} > "$OUT" 2>&1

# The stored user object doubles as the test fixture, unless a captured response is already there.
FIXTURE=resources/fixtures/upstream/onlyfans-madison420ivy.json
if [ ! -s "$FIXTURE" ]; then
  docker compose exec -T postgres psql -U app -d profiles -At -c \
    "select p.raw from profiles p join accounts c on c.id = p.account_id where c.slug = 'onlyfans' and p.username = 'madison420ivy'" > "$FIXTURE"
fi

cat "$OUT"
