# Profile Refresher — Fans API engineer test task

A small Laravel 13 service that fetches the full OnlyFans profile of
[madison420ivy](https://onlyfans.com/madison420ivy) through the Fans API and keeps it fresh with
Horizon workers, plus the incident from the task: reproduced, fixed and measured.

| Same workload ([details](#before-and-after)) | Old handler | Fixed handler |
|---|---|---|
| Refreshes the workers reported as successful | 50 of 50 | 50 of 50 |
| Refreshes with **correct stored data** | 26 of 50 (24 have likes = 0) | **50 of 50** |
| Busy account: upstream requests per correct refresh | 3.38 | 1.38 |
| Calm account: oldest waiting refresh | 15.9 s (stuck behind the busy account) | 1.9 s |

After the old handler's run, `profiles:repair` and the fixed handler brought all 50 profiles back
to correct data ([03b](docs/evidence/03b-repair-after-legacy.txt)).

[Run it](#run-it) · [Data source](#where-the-data-comes-from) · [The failure](#the-failure) ·
[The fix](#the-fix-protect-the-data) · [Queue](#keep-the-queue-moving) ·
[Before and after](#before-and-after) · [Worker crash](#worker-crash) ·
[Observability](#observability) · [Scaling](#the-next-scaling-limit) ·
[Production plan](#production-plan) · [Limits](#remaining-limits) · [Time spent](#time-spent)

## Run it

Needs Docker with Compose v2. Everything runs in containers: Horizon needs the `pcntl` and
`posix` extensions, which PHP on Windows does not have.

```sh
./setup.sh                    # .env, composer install, app key, migrate --seed, start the stack
# put a Fans API key into .env: FANSAPI_KEY=ofapi_...   then:
docker compose exec app php artisan profiles:fetch madison420ivy
```

UI: http://localhost:8000. Horizon: http://localhost:8000/horizon. The UI is a small operations view:
counts (profiles tracked, refreshes pending with the oldest wait, failing now, refreshed in 24 h), a
status per profile with Scout search, and per profile a health verdict, last attempt / success /
failure / next refresh, the attempt timeline and the raw upstream JSON. It updates itself while a
refresh is pending.
Checked from a fresh clone in an empty folder ([10](docs/evidence/10-clean-setup.txt)): `setup.sh`,
the test suite, and a keyless `profiles:fetch` recorded as `auth_failed` (HTTP 401). Only tested on
Windows 11 with Docker Desktop.

| Command (in the project folder, on the host) | |
|---|---|
| `docker compose exec app php artisan profiles:fetch madison420ivy [--queue]` | fetch now in this process, or hand it to Horizon |
| `docker compose exec app php artisan test` | 103 tests (Postgres and Redis of their own, never the real API) |
| `docker compose exec -e REFRESH_HANDLER=legacy app php artisan test --group=incident` | the incident tests against the **old** handler: 6 of 7 fail |
| `docker compose exec app php artisan workload:run --handler=legacy` / `--handler=v2` | the workload, before / after |
| `docker compose exec app php artisan profiles:repair --since="2026-11-01 18:00"` | re-queue profiles written during the incident |
| `docker compose exec app php artisan workload:crash --at=after-write` | kill a worker mid-refresh, watch the redelivery |
| `sh docs/evidence/run-all.sh` | re-create the evidence files (~8 min) |

**Public demo:** with `PUBLIC_READ_ONLY=true` and `PUBLIC_URL` set, requests that arrive through a
reverse proxy (they carry `X-Forwarded-For`, e.g. via Tailscale Funnel) can only read: no tracking,
no "refresh now" (both would spend Fans API credits), no Horizon dashboard
([`PublicDemo`](app/Http/Middleware/PublicDemo.php)). Requests made on the machine itself are unaffected.

Services: `app` (UI, Horizon dashboard), `horizon`, `scheduler` (queues due profiles every
minute), `postgres`, `redis` (AOF on), `fake-upstream` (a stand-in for the Fans API endpoint used
by the workload). `vendor/` is a named volume: bind-mounted on Docker Desktop for Windows, every PHP
boot took ~6 s (0.4 s now).

## Where the data comes from

**Fans API, `GET https://app.onlyfansapi.com/api/profiles/{username}`**
([Get Profile Details](https://docs.onlyfansapi.com/api-reference/public-profiles/get-profile-details))
with a read-only key. Its docs use `madison420ivy` as the example username.

- It needs no connected OnlyFans account, unlike `/api/{account}/users/{username}` (needs an
  `acct_...`, never cached). Cached answers cost 0 credits, `?fresh=true` costs 1. A cached
  snapshot older than 60 min (`FANSAPI_MAX_CACHE_AGE_MINUTES`) does not count as a refresh: the job
  records `cache_too_old` and asks again with `?fresh=true`. Live, the cache was 12 h old and said
  611,905 likes; the fresh call said 611,954 ([11](docs/evidence/11-live-fresh-fetch.txt), 1 credit).
- Calling onlyfans.com directly was rejected: its `api2` endpoints need per-request signatures
  built from rules that change, plus session cookies. Keeping that working is a product of its own,
  and it is the one Fans API already provides.
- **Likes are `data.favoritedCount`**; there is no `likes` field. There is no revision either, so
  the time Fans API took its snapshot (`_meta._cache.cached_at`, ms) is used as one: it only grows
  when a newer snapshot exists. Without it, the time the request was sent is used.
- Stored: the main fields as columns, and the **whole profile object (87 fields)** as `jsonb` in
  `profiles.raw`. The real response is a fixture:
  [`fansapi-madison420ivy.json`](resources/fixtures/upstream/fansapi-madison420ivy.json).

```text
$ php artisan profiles:fetch madison420ivy
| name                    | Madison Ivy                |
| upstream id             | 5140520                    |
| likes                   | 611,954                    |
| posts / photos / videos | 178 / 623 / 202            |
| fields stored (raw)     | 87                         |
| next refresh            | 2026-11-02 19:16:16        |   (above 100,000 likes: every 24 h)
| this attempt            | success, HTTP 200, 1492 ms |   (?fresh=true after a 12-hour-old cache hit)
```

## The failure

The incident handler is kept as [`LegacyRefreshProfile`](app/Jobs/LegacyRefreshProfile.php)
(`REFRESH_HANDLER=legacy`, for reproduction only). It reads `likes` from the top level, turns a
missing value into 0 and marks every response as a successful refresh. To reproduce the queue
symptom it also retries the way a lot of code does: `Http::retry(3, 1500)` inside the job,
sleeping while it holds a worker.

What it does ([`IncidentRegressionTest`](tests/Feature/IncidentRegressionTest.php); profile at
likes 120,000, revision 10):

| Upstream answer | Old handler stores | Marked as | Correct behaviour |
|---|---|---|---|
| `{"likes":120000,"revision":10}` (old format) | 120,000 / rev 10 | success | same |
| `{"profile":{"likes":121000},"revision":11}` (new) | **0** / rev 11 | success | 121,000 / rev 11 |
| 429 without Retry-After (after 3 in-job retries) | **0** / rev null | success | keep data, retry later |
| 500 with an empty body | **0** / rev null | success | keep data, retry later |
| 200 without likes | **0** | success | keep data, invalid response |
| rev 11, then a late rev 10 | **rev 10** | success | keep rev 11 |

Evidence: [01](docs/evidence/01-incident-tests-before-fix.txt) — 6 of 7 fail at commit `83496a9`,
where only the old handler exists; [01b](docs/evidence/01b-incident-tests-legacy-now.txt) — still
6 of 7 against the old handler today; [02](docs/evidence/02-incident-tests-after-fix.txt) — 7 of 7
pass with the fix. (The 7th test was tightened after 01 was recorded: a second delivery of a finished
refresh is now skipped instead of re-run; 01b and 02 show the current version.)

The three symptoms without a deployment: the upstream moved `likes` into `profile` and started
sending 429s and empty 500s, and the handler writes 0 for all of them (*stale or empty data*); the
job never fails whatever the upstream says (*workers report completed jobs*); and each rate-limited
job sleeps through its retries holding a worker while the FIFO queue keeps the other accounts
behind the burst (*oldest jobs keep getting older, one busy account delays the others*).

## The fix: protect the data

[`RefreshProfile`](app/Jobs/RefreshProfile.php) → [`ProfileRefresher`](app/Refresh/ProfileRefresher.php):
fetch, validate, then write or classify the failure.

- **Validate before writing** ([`ProfilePayloadParser`](app/Upstream/ProfilePayloadParser.php)).
  Accepts the Fans API format, the old top-level `likes` and the new `profile.likes`. Likes must be
  present and a whole number ≥ 0: explicit 0 is valid; missing, negative, `"121k"`, `1.5`, `true`,
  `null` are not; different values in both places are a conflict. The revision must be present.
  Optional fields with a wrong type or a value that would not fit the column are skipped and keep
  their stored value.
- **Only a newer revision writes** ([`ProfileStore`](app/Refresh/ProfileStore.php)): one statement,
  `UPDATE ... WHERE revision IS NULL OR revision < :new`, so of two racing responses only the newer
  can win whatever order the workers commit in. The same revision again is `unchanged` (confirmed,
  nothing rewritten), an older one `stale_ignored`. The same revision with *different* likes does
  write: the upstream says that version has other data, so the stored row is the wrong one (this
  is how zeros the old handler stored at revision 11 get corrected).
- **Failures never touch data.** They update `last_attempt_*`, `last_failure_at` and
  `last_failure_reason` only (and `consecutive_failures` once per failed refresh, not per attempt);
  `last_success_at` moves only on a valid response.
- **No duplicates.** Profiles are unique per `(account_id, username)` and created with
  `createOrFirst`. A refresh is queued only after a conditional `UPDATE` claims the profile
  (`refresh_queued_at`, `pending_refresh_id`), so the scheduler, UI and CLI cannot queue it twice.
  Only the claim's owner releases it, and a job whose refresh no longer holds the claim (finished by
  an earlier delivery, or replaced after the claim expired at 6 h) is dropped before the account
  gate, without calling the upstream.
- **Schedule** ([`RefreshPolicy`](app/Refresh/RefreshPolicy.php)): above 100,000 likes every 24 h,
  100,000 and below every 72 h. A refresh that fails for good is retried after 1 h, doubling with
  each consecutive failed refresh up to 72 h. A stale answer pushes the next refresh out, never earlier.
- **Repair** ([`profiles:repair`](app/Console/Commands/RepairProfiles.php)): the old handler marked
  its zeros as successes and scheduled the next refresh 24–72 h out, so they would stay visible that
  long. The command clears the revision of every profile marked successful since the incident
  started (so anything stored at the real revision is rewritten, not only likes) and queues it now.

## Keep the queue moving

Each job run is **one upstream attempt**. A retry is a `release()` with a delay, never a sleep
in the job, so a waiting refresh holds no worker.

| Upstream result | Outcome | What happens | Tries per refresh |
|---|---|---|---|
| 429 | `rate_limited` | the **whole account** pauses (`Retry-After` if sent, else a randomised exponential delay); the job retries after it | 8 |
| 5xx, incl. an empty 500 | `server_error` | randomised exponential backoff, 5 s base, 60 s cap (rides out ~30 s of errors) | 4 |
| timeout, 408, 504 | `timeout` | same | 4 |
| 404, 410 / 401, 403 / other 4xx / invalid body | `not_found` / `auth_failed` / `client_error` / `invalid_response` | fails at once, data kept | 1 |

At most 10 upstream attempts per refresh. "Randomised" is equal jitter: half the delay fixed, half
random, so retries neither fire instantly nor arrive together. There is deliberately no
`retryUntil()` and no fixed number of tries (`$tries = 0`): both also count time spent waiting in
the queue, and would fail refreshes that never reached the upstream exactly when the queue is backed
up. Unexpected exceptions, and workers dying on the job (`#[CountCrashesAsExceptions]`), stop it
after 3 (`$maxExceptions`).

**Account isolation** ([`AccountGate`](app/Jobs/Middleware/AccountGate.php)). Before calling the
upstream a job checks its account: in cooldown after a 429 → back to the queue until it ends
(+0–2 s spread); at its concurrency limit (2 by default, `accounts.max_concurrency`) → back in
1–3 s. Neither calls the upstream or spends a try. A rate-limited account therefore occupies a
worker for milliseconds, not seconds, and other accounts keep being served. The price: those jobs go
back into the one shared queue again and again, which is fine for a burst of 40 but would become
churn with tens of thousands queued for one account (see [scaling](docs/scaling.md)).

### Timeouts and `retry_after`

```
HTTP timeout 10 s (connect 3 s)  <  job timeout 30 s  <  Redis retry_after 90 s
                                    (Horizon supervisor timeout 45 s: the default for jobs without their own)
```

- **`retry_after`** is how long Redis waits for a reserved job to be acknowledged before it gives it
  to another worker. Every job's effective timeout must be below it, with margin, or a second worker
  starts a job the first one is still running.
- **The job timeout** is enforced by the worker: past 30 s the worker process is killed, the run
  counts towards `$maxExceptions` (3), and Redis hands the job out again only after `retry_after`.
  The HTTP timeout keeps one slow upstream call well inside it.

**Two workers on the same long-running attempt:** (1) the nesting above, so redelivery only
happens once the first worker is gone; (2) `WithoutOverlapping` per profile, with a lock that
outlives the job timeout, so a duplicate waits instead of running alongside; (3) the claim check,
so a redelivered job whose refresh already finished does nothing; (4) idempotent writes (revision
guard). For work that legitimately runs longer than `retry_after` I would split it into short jobs
or extend a lease while it runs, rather than raise `retry_after` for everything.

## Before and after

`php artisan workload:run --handler=legacy|v2`, against the local
[fake upstream](fake-upstream/server.php), processed by the running Horizon (4 workers). Every
profile starts at likes 120,000 / rev 10; the upstream now answers 121,000 / rev 11, alternating old
and new format (a partial rollout).

| Parameter | Value |
|---|---|
| Busy account | 40 profiles, queued first as one burst; 429 to every request for the first 6 s, then at most 5 requests/s; every 10th request an empty 500 |
| Calm account | 10 profiles, queued right after the burst; always valid answers |
| Upstream latency / workers / account concurrency | 50 ms / 4 / 2 |
| Old handler's retries | 3 tries, 1.5 s apart, inside the job |

Results, both runs from one `run-all.sh` pass ([03](docs/evidence/03-workload-before-fix-legacy.txt),
[04](docs/evidence/04-workload-after-fix-v2.txt); JSON with a per-second timeline next to them):

| | Old: busy | Old: calm | Fixed: busy | Fixed: calm |
|---|---|---|---|---|
| Reported successful | 40 | 10 | 40 | 10 |
| **Stored data correct** (121,000 / rev 11) | **21** | **5** | **40** | **10** |
| Stored data wrong (likes 0) | 19 | 5 | 0 | 0 |
| Upstream requests (counted by the fake upstream) | 71 | 10 | 55 | 10 |
| **Requests per correct refresh** | **3.38** | **2.0** | **1.38** | **1.0** |
| Upstream answers | 36×200, 33×429, 2×500 | 10×200 | 40×200, 10×429, 5×500 | 10×200 |
| **Oldest waiting refresh (max)** | 16.4 s | **15.9 s** | 44.8 s | **1.9 s** |
| All finished after | 16.0 s | 15.5 s | 44.1 s | 1.2 s |
| API keys in logs | 0 | 0 | 0 | 0 |

- **Old:** everything "succeeds" and about half the data is wrong. The calm account cannot start
  until the busy burst has slept through its retries.
- **Fixed:** all 50 correct. The calm account is served while the busy one is rate limited. A 429
  pauses the whole busy account instead of each job hammering on, and its refreshes recover as soon
  as the storm ends.
- The busy account takes *longer* to finish with the fix, because it now respects the limit instead
  of writing zeros. At 5 requests/s after a 6 s storm the floor is ~14 s; the rest is backoff that
  grows with each 429, and the patient 5xx retries (5 s base) for its five empty 500s. Pacing each
  account with a token bucket would close most of that gap.
- "Oldest waiting refresh" runs from the moment a refresh is queued until it finishes (applied or
  failed for good); a retry in Redis's delayed set is still waiting.

## Worker crash

`php artisan workload:crash --at=after-write` makes the Horizon worker that picks up a refresh kill
itself (`SIGKILL`) right after the database commit, before the queue hears the job finished. This is a
local-only test seam ([`ProfileRefresher::crashForTest`](app/Refresh/ProfileRefresher.php)), armed by a
one-shot Redis key. Then the command waits for Redis to hand the job out again
([05](docs/evidence/05-crash-after-write.txt), [06](docs/evidence/06-crash-before-write.txt)).

| | killed after the write | killed before the write |
|---|---|---|
| Right after the kill | data 121,000 / rev 11, claim released, job still reserved | data unchanged, claim held, job reserved |
| Redelivered after | 91 s (`retry_after` 90 s) | 91 s |
| Second delivery | skipped: refresh already finished, no upstream call | did the refresh: `success` |
| Profile rows / failed jobs | 1 / 0 | 1 / 0 |
| Upstream requests / attempts recorded | 1 / 1 | 2 / 1 |

**What this proves:** a worker process that dies mid-job (`SIGKILL`, as the OOM killer sends) loses
neither the refresh nor data integrity; Redis redelivers after `retry_after`, the lock and the
concurrency slot expire on their own, and the second run is harmless. A job that keeps killing its
worker stops after 3 crashes (`#[CountCrashesAsExceptions]`).

**What it does not prove:** losing Redis itself (the reserved job lives in Redis; AOF with
`everysec` can lose up to a second), a crash during the commit, a commit whose acknowledgement never
reached the worker, network partitions, or a frozen rather than dead machine. It also shows a gap:
a worker that dies between the upstream call and recording the attempt leaves that call unrecorded.

## Observability

Every refresh event is one JSON line in `storage/logs/refresh.log` with `refresh_id`, `account`,
`account_id`, `profile_id`, `username`, `job_id`, `job_attempt`, `upstream_attempt`, `outcome`,
`http_status`, `retry_in_seconds`, `duration_ms` (and `format`, `revision`, `likes`,
`memory_peak_mb` on success). Events: `queued`, `deferred` (debug), `retry_scheduled`,
`cache_too_old`, `success` / `unchanged` / `stale_ignored`, `failed`, `gave_up`, `skipped_not_pending`. One refresh through a 429
storm and its recovery (`profiles:fetch calm-001`: first attempt in the CLI, retries in Horizon;
real lines, abridged):

```text
$ grep 6e4fcb4b-49d5-4e97-9d89-a9d91a009394 storage/logs/refresh.log
19:35:56.78 refresh.queued           profile_id=94 account_id=5 source=manual handler=v2
19:35:56.88 refresh.retry_scheduled  account=calm username=calm-001 job_attempt=1 upstream_attempt=1 outcome=rate_limited http_status=429 retry_in_seconds=2
19:35:58.34 refresh.deferred         job_id=4c715e9b-... reason=account_cooldown delay_seconds=1
19:35:59.45 refresh.retry_scheduled  job_id=4c715e9b-... job_attempt=2 upstream_attempt=2 outcome=rate_limited http_status=429 retry_in_seconds=4
19:36:03.48 refresh.unchanged        job_id=4c715e9b-... job_attempt=3 upstream_attempt=3 http_status=200 format=new revision=11 likes=121000
```

The same refresh is in `refresh_attempts` (one row per upstream request) and on the profile page;
Horizon jobs are tagged `profile:`, `account:`, `refresh:`, and Horizon raises `LongWaitDetected`
when the refresh queue waits over 60 s (no notification channel is configured in this project). **Secrets:** the key only goes into the `Authorization` header; it is encrypted at
rest or read from `FANSAPI_KEY` and never stored; connection errors are scrubbed before logging. A
test and every workload run check the logs for keys: 0 found.

**Memory:** the highest worker peak seen over 3,000 refreshes was 36.5 MB; workers recycle after
1,000 jobs or an hour (64 MB limit). The scheduler peaks at 30 MB whether it queues 3,000 or 30,000
profiles ([09](docs/evidence/09-scheduler-memory.txt)). Bodies over 1 MB are refused before decoding
(they are still read into memory first; a streaming size cap is not done).

## The next scaling limit

Full write-up: [docs/scaling.md](docs/scaling.md). In short: one machine did **55 refreshes/s with 4
workers** (~73 ms each, 54 ms of it the upstream call), and the scheduler queues **650–700/s** per process
([07](docs/evidence/07-throughput.txt), [09](docs/evidence/09-scheduler-memory.txt)). At 579/s on
average, the **scheduler is the first thing to break**: it barely matches the average and is below
any peak. Next come Postgres writes (3 per refresh, plus `refresh_attempts` at ≥ 50 M rows/day) and,
outside our control, the upstream's per-team limit: our key allows 60 requests/min (the docs list 60,
1,000 and 5,000 per minute by plan, i.e. 1–83/s per team). **First change:** pipeline each claimed batch into Redis, log per batch instead of per job, run several
scheduler shards (the `SKIP LOCKED` claim already allows it), and jitter `next_refresh_at`.

## Production plan

**First 15 minutes**

1. *Confirm and size (0–5 min).* Horizon: wait time and throughput per queue, failed jobs. Attempts
   and logs: outcome and HTTP status per account over the last hour. A jump in 429s/500s or a new
   response shape points at the upstream, since nothing was deployed. Data:
   `SELECT count(*) FROM profiles WHERE likes_count = 0 AND last_success_at > now() - interval '1 hour'`.
2. *Stop the damage (5–10 min).* Every processed job may overwrite good data with 0, so pause the
   refresh supervisor (`horizon:pause-supervisor`, run in the horizon container) and the scheduler.
   Stale data is better than wrong data. Other queues keep running.
3. *Capture and tell (10–15 min).* Save sample raw responses of the new shape, write down when it
   started (for the repair), list the affected accounts, post in the incident channel what is paused
   and why.

**Rollout:** deploy with the scheduler paused; run one low-volume account first and compare its
stored data with the upstream by hand; resume the supervisor and the scheduler and watch for 15–30
minutes; then `profiles:repair --since=<start>`, one account at a time, largest last, so the
re-fetches do not trip the rate limits again.

**Rollback:** going back to the old handler would bring the zeros back, so rollback means pausing
the refresh supervisor and reverting the deploy. Triggers: `invalid_response` above a few percent
(a format nobody has seen), correct refreshes below the pre-incident baseline, the oldest waiting
refresh growing for more than 10 minutes, or database errors.

**Verifying recovery:** the oldest waiting refresh back to normal for every account; upstream
requests per success back near 1; no profiles with likes 0 and a recent `last_success_at` unless the
upstream really says 0; 429s per account within budget; a spot check of ~20 profiles fetched by hand
against what is stored.

## Remaining limits

- **Only tested on Windows 11 + Docker Desktop.** macOS should work; Linux may need the `chmod` above.
- **`favoritedCount` = likes comes from the field name and the Fans API docs**; I could not open
  onlyfans.com to compare with the profile page.
- **Real Fans API failures were never seen.** Every live call returned 200; 429/500/timeout handling
  is tested against fakes. Whether Fans API sends `Retry-After` is unknown (both handled).
- **The 60-minute cache limit is my choice.** Each refresh whose cache is older costs 1 credit;
  the right age for a 24 h interval is a product decision.
- **Rate limits are discovered, not paced**, and the gate re-queues waiting jobs instead of holding
  them per account; fine for this workload, churn at tens of thousands. No account-level circuit
  breaker for 5xx or 401/403 yet; each profile backs off on its own.
- **A worker killed between the upstream call and recording the attempt leaves the call unrecorded.**
- **Concurrency is tested sequentially** (two deliveries one after the other); the guarantees rest on
  single conditional statements and the per-profile lock.
- `refresh_attempts` has no retention; deferral log lines are noisy at debug level.
- The UI has no login (local only). The old handler and the crash seam stay for reproduction; both
  are inert in production (`REFRESH_HANDLER=v2`; the seam needs `APP_ENV=local`). I would delete the
  old handler after the incident review.

## Time spent

Wall-clock from my log, breaks excluded; the commits carry the same times. The code was written by
Claude Code working as an agent; see [AI.md](AI.md).

| Part | Time |
|---|---|
| Reading the task, choosing the data source, API key, plan | 0:25 |
| Docker setup, incl. ~15 min lost on a slow Alpine mirror | 0:20 |
| Data retrieval, incident reproduction, the fix, tests | 0:20 |
| Workload, fake upstream, crash test, UI | 0:10 |
| Own review, throughput and memory checks, repair, evidence, README, clean-clone check | 0:15 |
| First independent review and its fixes, evidence, packaging | 0:30 |
| *Second session:* second independent review and its fixes, live stale-cache check, UI redesign (a parallel agent), merge, final evidence, clean-setup check, packaging | 0:54 |
| **Total for the task** (20:20–22:20 and 22:28–23:22) | **2:54** |
| *Afterwards (00:00–00:30):* read-only public demo mode, hosting it through Tailscale Funnel | 0:30 |

## Layout

```
app/Upstream/       FansApiClient, ProfilePayloadParser (validation), ProfileSnapshot
app/Refresh/        ProfileRefresher (one attempt), ProfileStore (all writes), RefreshScheduler (claims),
                    Outcome, RefreshPolicy (24 h / 72 h), Backoff, AccountThrottle (cooldowns)
app/Jobs/           RefreshProfile (fixed), LegacyRefreshProfile (incident),
                    Middleware/SkipUnclaimedRefresh, Middleware/AccountGate
app/Console/        profiles:fetch|enqueue-due|repair, workload:run|check|crash|throughput|scheduler-memory
fake-upstream/      stand-in for the Fans API endpoint, used by the workload
resources/fixtures/ upstream response fixtures, incl. the real madison420ivy response
tests/              103 PHPUnit tests
docs/               scaling.md; evidence/ (tests, workload, crash, throughput, memory, clean setup, live fetch)
```
