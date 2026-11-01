# Profile Refresher — Fans API engineer test task

A small Laravel 13 service that fetches the full OnlyFans profile of
[madison420ivy](https://onlyfans.com/madison420ivy) through the Fans API, keeps it
fresh with Horizon workers, and the incident from the task: reproduced, fixed and
measured.

**Result in one table** (same workload before and after, details in [Before and after](#before-and-after)):

| | Old handler | Fixed handler |
|---|---|---|
| Refreshes the workers reported as successful | 50 of 50 | 50 of 50 |
| Refreshes with correct stored data | **27 of 50** (23 profiles have likes = 0) | **50 of 50** |
| Busy account: upstream requests per correct refresh | 3.27 | 1.38 |
| Calm account: oldest waiting refresh | 16.8 s (stuck behind the busy account) | 4.9 s |

After the old handler's run, `profiles:repair` plus the fixed handler brought all 50 profiles back to
correct data ([`03b-repair-after-legacy.txt`](docs/evidence/03b-repair-after-legacy.txt)).

Contents: [Run it](#run-it) · [Where the data comes from](#where-the-data-comes-from) ·
[The failure](#the-failure) · [The fix](#the-fix-protect-the-data) ·
[Queue behaviour](#keep-the-queue-moving) · [Before and after](#before-and-after) ·
[Worker crash](#worker-crash-replaying-a-job-after-its-database-write) ·
[Observability](#observability) · [Next scaling limit](#the-next-scaling-limit-50-million-jobs-a-day) ·
[Production plan](#production-plan) · [Limits and unfinished work](#remaining-limits-and-what-is-not-done) ·
[Time spent](#time-spent)

## Run it

Needs Docker with Compose v2. Everything runs in containers: Horizon needs the
`pcntl` and `posix` extensions, which PHP on Windows does not have.

```sh
./setup.sh                          # .env, composer install, key, migrate --seed, start the stack
# put a Fans API key in .env: FANSAPI_KEY=ofapi_...  (a free account works; see below)
docker compose exec app php artisan profiles:fetch madison420ivy
```

Then open http://localhost:8000 (profiles, search, refresh button) and
http://localhost:8000/horizon.

| What | Command (prefix with `docker compose exec app`) |
|---|---|
| Fetch a profile now, in this process | `php artisan profiles:fetch madison420ivy` |
| Queue it for Horizon instead | `php artisan profiles:fetch madison420ivy --queue` |
| Tests (87) | `php artisan test` |
| The incident tests against the **old** handler (they fail) | `REFRESH_HANDLER=legacy php artisan test --group=incident` (use `docker compose exec -e REFRESH_HANDLER=legacy app ...`) |
| Workload, before / after | `php artisan workload:run --handler=legacy` / `--handler=v2` |
| Repair profiles written during the incident | `php artisan profiles:repair --since="2026-11-01 18:00"` |
| Kill a worker mid-refresh and watch the redelivery | `php artisan workload:crash --at=after-write` |
| Throughput and memory check | `php artisan workload:throughput --profiles=3000` |
| All evidence files again (~6 min) | `sh docs/evidence/run-all.sh` |

Services: `app` (UI and Horizon dashboard, `php artisan serve`), `horizon`,
`scheduler` (`schedule:work`: queues due profiles every minute), `postgres`,
`redis` (AOF on), `fake-upstream` (a stand-in for the Fans API used by the workload).
`vendor/` lives in a named volume because a bind-mounted `vendor/` on Docker Desktop
for Windows made every PHP boot take ~6 s (0.4 s now).

Tested on Windows 11 with Docker Desktop 28 only. Linux hosts may need
`chmod -R a+w storage bootstrap/cache .env`, because the containers run as `www-data`.

## Where the data comes from

**Fans API, `GET https://app.onlyfansapi.com/api/profiles/{username}`**
([Get Profile Details](https://docs.onlyfansapi.com/api-reference/public-profiles/get-profile-details)),
with a read-only API key. Its documentation uses `madison420ivy` as the example username.

- It needs no connected OnlyFans account, unlike `/api/{account}/users/{username}`
  (needs an `acct_...` and is never cached).
- Cached answers cost 0 credits; `?fresh=true` costs 1. `FANSAPI_FRESH=false` by default.
  The key I used had 100 credits and a 60 requests/minute limit; the whole task used 0 credits
  (every live call was a cache hit).
- Calling onlyfans.com directly was rejected: the site is a SPA whose `api2` endpoints need
  per-request signatures (`sign`/`time`/`app-token`) built from rules that change, plus session
  cookies. That is fragile and against OnlyFans' terms, and it is exactly what Fans API sells.

**Likes are `data.favoritedCount`** (the response has no `likes` field). Fans API has no
`revision` either, so the time Fans API took its snapshot (`_meta._cache.cached_at`, in
milliseconds) serves as the revision: it only grows when a newer snapshot exists. Without
it, the time we sent the request is used.

What is stored: the main fields as columns (id, name, about, avatar, header, verified, price,
likes, posts/photos/videos/audios/media counts, subscribers, joined, last seen) and the
**whole profile object (87 fields)** as `jsonb` in `profiles.raw`. A real response is in
[`resources/fixtures/upstream/fansapi-madison420ivy.json`](resources/fixtures/upstream/fansapi-madison420ivy.json):
likes 611,905, 178 posts, 623 photos, 202 videos. Above 100,000 likes, so it refreshes every 24 h.

```text
$ php artisan profiles:fetch madison420ivy
| name                    | Madison Ivy                     |
| upstream id             | 5140520                         |
| likes                   | 611,905                         |
| posts / photos / videos | 178 / 623 / 202                 |
| fields stored (raw)     | 87                              |
| next refresh            | 2026-11-02 19:16:16             |
| this attempt            | success, HTTP 200, 276 ms       |
```

## The failure

The incident handler is kept as [`app/Jobs/LegacyRefreshProfile.php`](app/Jobs/LegacyRefreshProfile.php)
(selectable with `REFRESH_HANDLER=legacy`, for reproduction only). It reads `likes` from the
top level, turns a missing value into 0 and marks every response as a successful refresh.
To reproduce the queue symptom too, it retries the way a lot of code does: `Http::retry(3, 1500)`
inside the job, sleeping while it holds the worker.

What it does with each case ([`tests/Feature/IncidentRegressionTest.php`](tests/Feature/IncidentRegressionTest.php),
profile starts at likes 120,000, revision 10):

| Upstream answer | Old handler stores | Marks as | Should be |
|---|---|---|---|
| `{"likes":120000,"revision":10}` (old format) | 120,000 / rev 10 | success | same (the one passing test) |
| `{"profile":{"likes":121000},"revision":11}` (new format) | **0 / rev 11** | success | 121,000 / rev 11 |
| 429 without Retry-After (after 3 in-job retries) | **0 / rev null** | success | keep 120,000 / rev 10, retry later |
| 500 with an empty body | **0 / rev null** | success | keep data, retry later |
| 200 without likes | **0** | success | keep data, invalid response |
| rev 11 then a late rev 10 | **119,000 / rev 10** | success | keep rev 11 |

Evidence: [`docs/evidence/01-incident-tests-before-fix.txt`](docs/evidence/01-incident-tests-before-fix.txt)
(6 of 7 fail, at commit `83496a9` where only the old handler exists) and
[`02-incident-tests-after-fix.txt`](docs/evidence/02-incident-tests-after-fix.txt) (7 of 7 pass).

Why the three symptoms appear without a deployment:

1. *Stale or empty data:* the upstream moved `likes` into `profile` and started sending
   429s and empty 500s; the handler writes 0 for all of them.
2. *Workers report completed jobs:* the job never fails, whatever the upstream said. A
   completed job says nothing about the data.
3. *Oldest jobs keep getting older, one busy account delays the others:* while the busy
   account is rate limited, each of its jobs sleeps through its retries holding a worker,
   and the queue is first-in-first-out, so the calm account's jobs wait behind the burst.

## The fix: protect the data

[`app/Jobs/RefreshProfile.php`](app/Jobs/RefreshProfile.php) → [`app/Refresh/ProfileRefresher.php`](app/Refresh/ProfileRefresher.php):
fetch → validate → write or classify the failure.

- **Validate before writing.** [`ProfilePayloadParser`](app/Upstream/ProfilePayloadParser.php)
  accepts the Fans API format, the old top-level `likes` and the new `profile.likes`. Likes must
  be present and a whole number ≥ 0: an explicit 0 is valid; missing, negative, `"121k"`, `1.5`,
  `true` or `null` are not. Both places present with different values is a conflict and rejected.
  The revision must be present too. Optional fields with a wrong type are skipped, so they keep
  their stored value instead of failing the response.
- **Newer revisions only.** [`ProfileStore::apply`](app/Refresh/ProfileStore.php) writes with
  one conditional statement, `UPDATE ... WHERE revision IS NULL OR revision < :new`, so of two
  racing responses only the newer can win, whatever order the workers commit in. The same
  revision again is recorded as `unchanged` (data confirmed, nothing rewritten); an older one as
  `stale_ignored`.
- **Failures never touch data.** A failed or malformed response only updates `last_attempt_*`,
  `last_failure_at`, `last_failure_reason` and `consecutive_failures`. `last_success_at` moves
  only on a valid response, so last attempt, last success and the failure stay distinguishable.
- **No duplicates.** Profiles are unique per `(account_id, username)` and created with
  `createOrFirst`. A refresh is queued only after a conditional `UPDATE` claims the profile
  (`refresh_queued_at`, `pending_refresh_id`), so the scheduler, the UI and the CLI cannot queue
  it twice; only the claim's owner can release it, so a replayed old job cannot release a newer
  claim. Claims older than 30 minutes are treated as lost and taken over.
- **Schedule.** Above 100,000 likes: every 24 h; 100,000 and below: every 72 h
  ([`RefreshPolicy`](app/Refresh/RefreshPolicy.php)). The scheduler claims due profiles in
  batches of 500 with `FOR UPDATE SKIP LOCKED`, so its memory stays flat and two schedulers
  cannot claim the same row. After a refresh fails for good, the profile is tried again in an hour.
- **Repair.** Re-fetching does not fix rows the old handler already broke: it stored 0 together
  with the real revision 11, so a fresh revision 11 is "unchanged". `profiles:repair --since=...`
  clears the revision of every profile marked successful since the incident started and queues
  it again ([test](tests/Feature/RepairProfilesTest.php)).

Tests (87, all passing, [`docs/evidence/08-full-test-suite.txt`](docs/evidence/08-full-test-suite.txt)):
response formats and validation (32), the incident cases (7), retries and failures (21),
account isolation (4), scheduling and duplicates (7), repair (3), pages (5), refresh interval
and backoff (8). Tests use Postgres (a separate database) and Redis (separate databases), never
the real API (`Http::preventStrayRequests()`).

## Keep the queue moving

Each job run is **one upstream attempt**. A retry is a `release()` with a delay, never a sleep
inside the job, so a waiting refresh holds no worker.

| Upstream result | Outcome | What happens | Retry budget per refresh |
|---|---|---|---|
| 429 | `rate_limited` | The **whole account** pauses: `Retry-After` if sent, otherwise a randomised exponential delay; the job comes back after it | 8 |
| 5xx, incl. empty 500 | `server_error` | Randomised exponential backoff (2 s base, 60 s cap) | 3 |
| Connect/read timeout, 408, 504 | `timeout` | Same | 3 |
| 404, 410 | `not_found` | Fails at once, data kept | — |
| 401, 403 | `auth_failed` | Fails at once | — |
| Other 4xx, invalid body | `client_error`, `invalid_response` | Fails at once | — |

On top of the budgets: at most 10 upstream attempts per refresh, and `retryUntil()` ends a
refresh 15 minutes after it was queued. A refresh that runs out goes back to the scheduler for an
hour later. "Randomised" is equal jitter: half the delay fixed, half random, so a retry never
fires instantly and many retries do not arrive together.

**Account isolation** ([`AccountGate`](app/Jobs/Middleware/AccountGate.php)). Before calling the
upstream, a job checks its account:

- in cooldown after a 429 → back to the queue until the cooldown ends (plus 0–2 s spread),
  without calling the upstream and without spending a retry;
- already at its concurrency limit (2 by default, `accounts.max_concurrency`) → back in 1–3 s.

So a rate-limited account holds at most 2 of the 4 workers for a few milliseconds each and
otherwise sits in Redis's delayed set, while other accounts keep being served.

### Timeouts and `retry_after`

```
HTTP timeout 10 s (connect 3 s)  <  job $timeout 30 s  <  Horizon supervisor timeout 45 s  <  Redis retry_after 90 s
```

- The **HTTP timeout** keeps a slow upstream from eating the job's time.
- The **job timeout** (30 s) is enforced by the worker: if a job runs longer, the worker process
  is killed. It must be shorter than the **worker/supervisor timeout** (45 s), which is only the
  default for jobs that set none.
- **`retry_after`** (90 s) is how long Redis waits for an acknowledgement before it hands a
  reserved job to another worker. If it were shorter than a job can run, a second worker would
  start the same job while the first is still on it. It has to be longer than the job timeout
  plus a margin, so a job is redelivered only when its worker is really gone.

**Two workers on the same long-running attempt** — four layers:

1. the nesting above, so redelivery only happens after the first worker is dead;
2. `WithoutOverlapping` per profile (lock expires after 45 s, longer than the job timeout):
   a duplicate delivery waits instead of running in parallel;
3. idempotent writes: the revision guard and the claim owner check make a second run harmless;
4. for work that legitimately runs longer than `retry_after`, I would split it into short jobs or
   extend a lease while it runs, rather than raising `retry_after` for everything.

## Before and after

`php artisan workload:run --handler=legacy|v2`: two accounts against the local
[fake upstream](fake-upstream/server.php), processed by the running Horizon (4 workers).
Every profile starts at likes 120,000 / revision 10; the upstream now answers likes 121,000 /
revision 11, alternating old and new format (a partial rollout).

| Parameter | Value |
|---|---|
| Busy account | 40 profiles, queued first as one burst. 429 to every request for the first 6 s, then at most 5 requests/s; every 10th request an empty 500 |
| Calm account | 10 profiles, queued right after the burst; always valid answers |
| Upstream latency | 50 ms per request |
| Workers / account concurrency | 4 / 2 |
| Old handler retries | 3 tries, 1.5 s apart, inside the job |

Results ([`03-workload-before-fix-legacy.txt`](docs/evidence/03-workload-before-fix-legacy.txt),
[`04-workload-after-fix-v2.txt`](docs/evidence/04-workload-after-fix-v2.txt); JSON with the
per-second timeline next to them):

| Metric | Old: busy | Old: calm | Fixed: busy | Fixed: calm |
|---|---|---|---|---|
| Refreshes reported successful | 40 | 10 | 40 | 10 |
| **Stored data checked: correct (121,000 / rev 11)** | **22** | **5** | **40** | **10** |
| Stored data: wrong (likes 0) | 18 | 5 | 0 | 0 |
| Upstream requests (counted by the fake upstream) | 72 | 10 | 55 | 10 |
| **Requests per correct refresh** | **3.27** | **2.0** | **1.38** | **1.0** |
| Upstream answers | 36×200, 30×429, 6×500 | 10×200 | 40×200, 10×429, 5×500 | 10×200 |
| **Oldest waiting refresh (max)** | 18.1 s | **16.8 s** | 31.1 s | **4.9 s** |
| All refreshes finished after | 18.3 s | 17.0 s | 30.4 s | 4.2 s |
| API keys found in logs | 0 | 0 | 0 | 0 |

How to read it:

- **Old:** everything "succeeds", half the data is wrong. The calm account cannot start until
  the busy burst has slept through its retries: its oldest refresh waits 16.8 s for 10 jobs that
  each take 50 ms. `profiles:repair` then fixes all of it (above).
- **Fixed:** all 50 are correct. The calm account is served while the busy one is rate limited
  (9 of 10 within a second; the 10th found both of its own slots busy and waited 1–3 s, hence
  4.9 s; in an earlier run 1.5 s). The busy account gets 10 429s instead of 30, because a 429
  pauses the whole account instead of each job hammering on, and its refreshes recover as soon as
  the storm ends: first successes at 7 s, all 40 by 30 s.
- The busy account takes *longer* to finish with the fix (30 s vs 18 s), because it now respects
  the limit instead of writing zeros. At 5 requests/s after a 6 s storm the floor is about 14 s;
  the rest is backoff growing with each 429. Pacing each account with a token bucket learned from
  its 429s would close that gap (see [scaling](#the-next-scaling-limit-50-million-jobs-a-day)).
- "Oldest waiting refresh" counts from the moment a refresh is queued until it finishes (applied
  or failed for good); a retry sitting in Redis's delayed set is still waiting.

## Worker crash: replaying a job after its database write

`php artisan workload:crash --at=after-write` makes the Horizon worker that picks up a refresh
kill itself with `SIGKILL` right after the database commit, before the queue is told the job
finished (a local-only test seam: [`ProfileRefresher::crashForTest`](app/Refresh/ProfileRefresher.php),
armed by a one-shot Redis key). Then it waits for Redis to hand the job out again.
([`05-crash-after-write.txt`](docs/evidence/05-crash-after-write.txt),
[`06-crash-before-write.txt`](docs/evidence/06-crash-before-write.txt))

| | killed after the write | killed before the write |
|---|---|---|
| Right after the kill | data 121,000 / rev 11, claim released, job still reserved in Redis | data unchanged, claim held, job reserved |
| Redelivered | after 91 s (`retry_after` 90 s) | after 90 s |
| Second delivery | `unchanged` — nothing rewritten | `success` — data written now |
| Profile rows / failed jobs | 1 / 0 | 1 / 0 |
| Upstream requests vs attempts recorded | 2 / 2 | 2 / **1** |

What this proves: a real worker process dying mid-job (the same signal the OOM killer sends)
does not lose the refresh and does not duplicate or corrupt data; Redis redelivers after
`retry_after`, the per-profile lock and the funnel slot expire on their own, and the second run
is idempotent.

What it does not prove: losing Redis itself (the reserved job lives in Redis; AOF with
`appendfsync everysec` can lose up to a second of it), a crash *during* the commit, a database
that committed but whose acknowledgement never reached the worker, network partitions, or a
machine that freezes rather than dies. It also shows a gap: a worker that dies between the
upstream call and recording the attempt leaves that call unrecorded (2 requests, 1 attempt row).

## Observability

Every refresh event is one JSON line in `storage/logs/refresh.log` with `refresh_id`, `account`,
`account_id`, `profile_id`, `username`, `job_id`, `job_attempt` (Laravel's count),
`upstream_attempt`, `outcome`, `http_status`, `retry_in_seconds`, `duration_ms`, and for
successes `format`, `revision`, `likes`, `memory_peak_mb`. Events: `refresh.queued`,
`refresh.deferred` (debug), `refresh.retry_scheduled`, `refresh.success` / `unchanged` /
`stale_ignored`, `refresh.failed`, `refresh.gave_up`. Following one refresh through a failure
and its recovery (`profiles:fetch calm-001` during a 429 storm: the first attempt runs in the CLI,
the retries in Horizon; real lines, fields abridged):

```text
$ grep 6e4fcb4b-49d5-4e97-9d89-a9d91a009394 storage/logs/refresh.log
19:35:56.78 refresh.queued           profile_id=94 account_id=5 source=manual handler=v2
19:35:56.88 refresh.retry_scheduled  account=calm username=calm-001 job_attempt=1 upstream_attempt=1 outcome=rate_limited http_status=429 retry_in_seconds=2
19:35:58.34 refresh.deferred         job_id=4c715e9b-... reason=account_cooldown delay_seconds=1
19:35:59.45 refresh.retry_scheduled  job_id=4c715e9b-... job_attempt=2 upstream_attempt=2 outcome=rate_limited http_status=429 retry_in_seconds=4
19:36:03.48 refresh.unchanged        job_id=4c715e9b-... job_attempt=3 upstream_attempt=3 http_status=200 format=new revision=11 likes=121000 duration_ms=4
```

One of those lines in full:

```json
{"message":"refresh.retry_scheduled","context":{"refresh_id":"6e4fcb4b-49d5-4e97-9d89-a9d91a009394","account_id":5,"account":"calm","profile_id":94,"username":"calm-001","job_id":"4c715e9b-3d30-4507-9c2a-3ccc24c566e3","job_attempt":2,"upstream_attempt":2,"outcome":"rate_limited","http_status":429,"reason":"HTTP 429","retry_in_seconds":4,"final":false,"duration_ms":23},"level":300,"level_name":"WARNING","channel":"local","datetime":"2026-11-01T19:35:59.459303+00:00","extra":{}}
```

The same refresh is in the `refresh_attempts` table (one row per upstream request) and on the
profile page; Horizon jobs are tagged `profile:{id}`, `account:{id}`, `refresh:{id}`.
**Secrets:** the API key goes only into the `Authorization` header, is encrypted at rest
(`accounts.api_key`, or read from `FANSAPI_KEY` and never stored), hidden from serialisation,
and connection errors are scrubbed before logging. A test and every workload run check the logs
for keys: 0 found.

**Memory** ("without burning too much RAM"): a worker's peak is 36.5 MB and stays there
(`memory_peak_mb` in the log); workers are recycled after 1,000 jobs or an hour, with a 64 MB
limit. The scheduler peaks at 30.0 MB whether it queues 3,000 or 29,458 profiles, because it
claims and dispatches in batches of 500 and never loads profiles as models. Responses over
1 MB are refused before decoding.

## The next scaling limit: 50 million jobs a day

50 M/day is 579 jobs/s on average. Measured locally (`workload:throughput`, one healthy account,
50 ms upstream latency, 4 workers, [`07-throughput.txt`](docs/evidence/07-throughput.txt)):
**55 refreshes/s, 13.7 per worker**, so ~73 ms per refresh: ~54 ms upstream call and ~19 ms our
own work (7 SQL statements: 4 reads and 3 writes, plus Redis for the gate, the lock and Horizon).
The scheduler claimed and dispatched **660–720 refreshes/s** in one process.

**What I would measure next**

| Question | Signal |
|---|---|
| Peak arrivals | refreshes queued per second, per account and in total; how much the 24 h/72 h schedule bunches up (profiles added together come due together) |
| Job duration | p50/p95/p99 of upstream latency and of our own time per attempt |
| Concurrent work | in-flight jobs = arrival rate × duration (Little's law): 579/s × 72 ms ≈ 42 busy workers on average; ×3 at peak, more when the upstream slows |
| Retry amplification | upstream requests per successful refresh, by outcome (1.38 under the busy scenario) |
| Database | statements/s (7 per refresh ≈ 4 k/s on average, 1.7 k of them writes), WAL volume, `refresh_attempts` growth (≥ 50 M rows/day), lock waits on `profiles` |
| Redis | ops/s, memory used by Horizon's job metadata, delayed-set size |
| Upstream limits | 429 rate per account and per Fans API team, limits per plan (the docs give 1,000–5,000 RPM per team) |
| Largest accounts | profiles and refreshes per account: one account with millions of profiles and a 2-slot cap cannot keep up |

**Likely bottleneck in this solution: the scheduler.** One process queues ~700/s, barely above
the 579/s average and well below any peak, and every dispatch also writes a log line and Horizon
metadata. Close behind: three writes per refresh into Postgres plus an ever-growing
`refresh_attempts` table. The hard ceiling outside our control is the upstream's per-team rate
limit.

**Evidence I would collect:** scheduler lag (age of the oldest due-but-unclaimed profile) and
loop duration; Horizon wait time per queue; `pg_stat_statements` top statements by total time;
Redis `INFO commandstats`; 429s per account.

**First change:** make queueing cheap and parallel. Dispatch each claimed batch in one Redis
pipeline instead of one round trip per job, drop the per-job `refresh.queued` line to sampled
debug, run several scheduler shards (the `SKIP LOCKED` claim already allows it), and add jitter
to `next_refresh_at` so the 24 h/72 h schedule does not come due in waves. After that:
partition `refresh_attempts` by day with a short retention, and pace each account with a token
bucket sized to its upstream limit instead of discovering the limit through 429s.

A local run of a few thousand jobs is not evidence that this handles 50 M/day; it gives a
per-worker rate to size from and shows where to look first.

## Production plan

**First 15 minutes**

1. *Confirm and size it (0–5 min).* Horizon: wait time and throughput per queue, failed jobs.
   Logs and `refresh_attempts`: outcome and HTTP status per account over the last hour — a jump
   in 429s/500s or in a new response shape points at the upstream, since nothing was deployed.
   Data: profiles "successfully" refreshed with likes 0 in the last hour
   (`SELECT count(*) FROM profiles WHERE likes_count = 0 AND last_success_at > now() - interval '1 hour'`).
2. *Stop the damage (5–10 min).* Every processed job may overwrite good data with 0, so pause the
   refresh supervisor (`horizon:pause-supervisor` in the horizon container) and stop the scheduler
   from queueing more. Stale data is better than wrong data. Keep other queues running.
3. *Capture and tell (10–15 min).* Save sample raw responses of the new shape, note the incident
   start time for the repair, list the affected accounts, and post in the incident channel what is
   paused and why.

**Rollout of the fix**

1. Deploy with the scheduler still paused. Run the fixed handler for one internal or low-volume
   account first and check its stored data against the upstream by hand.
2. Resume the supervisor and the scheduler; watch for 15–30 minutes.
3. Repair: `profiles:repair --since=<incident start>`, account by account, largest last, so the
   re-fetches do not trigger the rate limits again.

**When to roll back.** Going back to the old handler would bring the zeros back, so "rollback"
means pausing the refresh supervisor again and reverting the deploy if needed. Triggers: an
`invalid_response` rate above a few percent (a third format nobody has seen), verified successes
below the pre-incident baseline, the oldest waiting refresh growing for more than 10 minutes, or
database errors.

**Verifying recovery.** Oldest waiting refresh back to its normal level for every account;
upstream requests per success back near 1; no rows with likes 0 and a recent `last_success_at`
unless the upstream really says 0; 429s per account within budget; and a spot check of ~20
profiles fetched by hand and compared with what is stored.

## Remaining limits and what is not done

- **Not tested outside Windows 11 + Docker Desktop.** `setup.sh` should work on macOS; on Linux
  file permissions may need the `chmod` above.
- **`favoritedCount` = likes is taken from the field name and the Fans API docs.** I could not
  open onlyfans.com to compare with the number shown on the profile page.
- **Real Fans API failures were not exercised.** The live calls all returned 200 from the cache;
  429/500/timeout handling is tested against fakes. I do not know whether Fans API sends
  `Retry-After` (both cases are handled).
- **`?fresh=true` was not used.** With the default, a "refresh" can return Fans API's cached
  snapshot; that is recorded as `unchanged`, not as new data. Whether the cache is fresh enough
  for a 24 h interval is a product decision.
- **Rate limits are discovered, not paced.** The busy account in the workload took ~30 s where
  ~14 s was possible.
- **A worker killed between the upstream call and recording the attempt leaves the call
  unrecorded** (seen in the before-write crash test).
- **`refresh_attempts` has no retention**, and the per-job deferral log lines are noisy.
- The UI has no authentication (local only); Horizon's dashboard is open in `local` only.
- The old handler and the crash test seam stay in the code for reproduction; both are inert in
  production (`REFRESH_HANDLER=v2`, seam only when `APP_ENV=local`). I would delete the old
  handler after the incident review.
- The fake upstream is PHP's built-in server; fine for these workloads, not a load generator.

## Time spent

See the table at the end, filled in from my log. Work was done with Claude Code as an agent;
see [AI.md](AI.md).

| Part | Time |
|---|---|
| Reading the task, choosing the data source, environment, API key | 0:20 |
| Docker setup (a slow Alpine mirror cost ~15 min) | 0:25 |
| Data retrieval, incident reproduction, the fix, tests | 0:35 |
| Workload, fake upstream, crash test, UI | 0:30 |
| Review fixes, throughput check, repair command | 0:25 |
| Evidence runs, README, AI.md, clean-clone check | TBD |
| **Total** | **TBD** |

## Layout

```
app/Upstream/       FansApiClient, ProfilePayloadParser (validation), ProfileSnapshot
app/Refresh/        ProfileRefresher (one attempt), ProfileStore (all writes), RefreshScheduler (claims),
                    Outcome, RefreshPolicy (24 h / 72 h), Backoff, AccountThrottle (cooldowns)
app/Jobs/           RefreshProfile (fixed), LegacyRefreshProfile (incident), Middleware/AccountGate
app/Console/        profiles:fetch, profiles:enqueue-due, profiles:repair, workload:run|crash|throughput
fake-upstream/      server.php — stand-in for the Fans API endpoint, used by the workload
resources/fixtures/ upstream response fixtures (incl. the real madison420ivy response)
tests/              87 tests (PHPUnit), Postgres + Redis
docs/evidence/      test runs, workload reports, crash and throughput runs, run-all.sh
```
