# Profile Refresher — Fans API engineer test task

A small Laravel 13 service that fetches the full OnlyFans profile of
[madison420ivy](https://onlyfans.com/madison420ivy) **straight from onlyfans.com**: the same signed
`api2` request OnlyFans' web app makes, with a guest session. Horizon workers keep the profile
fresh. The incident from the task is reproduced, fixed and measured.

| Same workload ([details](#before-and-after)) | Old handler | Fixed handler |
|---|---|---|
| Refreshes the workers reported as successful | 50 of 50 | 50 of 50 |
| Refreshes with **correct stored data** | 26 of 50 (24 have likes = 0) | **50 of 50** |
| Busy account: upstream requests per correct refresh | 3.24 | 1.35 |
| Calm account: oldest waiting refresh | 15.5 s (stuck behind the busy account) | 1.5 s |

After the old handler's run, `profiles:repair` and the fixed handler brought all 50 profiles back
to correct data ([03b](docs/evidence/03b-repair-after-legacy.txt)).

[Changes after the review](#changes-after-the-review) · [Run it](#run-it) ·
[Data source](#where-the-data-comes-from) · [The failure](#the-failure) ·
[The fix](#the-fix-protect-the-data) · [Queue](#keep-the-queue-moving) ·
[Before and after](#before-and-after) · [Worker crash](#worker-crash) ·
[Observability](#observability) · [Scaling](#the-next-scaling-limit) ·
[Production plan](#production-plan) · [Limits](#remaining-limits) · [Time spent](#time-spent)

## Changes after the review

| Review finding | Change | Tests / evidence |
|---|---|---|
| The integration called the managed Fans API endpoint instead of OnlyFans | [`OnlyFansClient`](app/Upstream/OnlyFans/OnlyFansClient.php): `GET onlyfans.com/api2/v2/users/{username}` with `sign`, `time`, `app-token`, `x-bc`, `user-id` generated here ([`RequestSigner`](app/Upstream/OnlyFans/RequestSigner.php)), current rules loaded at run time ([`SigningRulesProvider`](app/Upstream/OnlyFans/SigningRulesProvider.php)), one guest or logged-in session per account with its cookies ([`SessionStore`](app/Upstream/OnlyFans/SessionStore.php)). It is the default driver; the Fans API client stays as the `fansapi` driver for comparison. | [`OnlyFansClientTest`](tests/Feature/OnlyFansClientTest.php), [`RequestSignerTest`](tests/Unit/RequestSignerTest.php), live: [14](docs/evidence/14-live-onlyfans-fetch.txt), rules rotation: [13](docs/evidence/13-workload-rules-rotation.txt) |
| The response to the forced-fresh request was not checked for stale data | Every response goes through the same checks. If `?fresh=true` still returns an old snapshot, it is not stored: `cache_still_too_old`, retried with backoff. | [12](docs/evidence/12-review-fixes-tests.txt) |
| That extra request could exceed the ten-attempt limit | The budget is checked before every outbound call. A follow-up that would be the 11th is not sent (the refresh fails), and a job redelivered with no attempts left sends nothing. | [12](docs/evidence/12-review-fixes-tests.txt) |
| The profile update and the attempt record were committed separately | One transaction for both, on success and on failure: a crash leaves both or neither. | [12](docs/evidence/12-review-fixes-tests.txt) |

All four regression tests for the three reliability findings fail on the reviewed code (recorded
with the fix stashed) and pass now. Two older tests had only passed because of the first finding;
see [AI.md](AI.md).

## Run it

Needs Docker with Compose v2. Everything runs in containers: Horizon needs the `pcntl` and
`posix` extensions, which PHP on Windows does not have.

```sh
./setup.sh                    # .env, composer install, app key, migrate --seed, start the stack
docker compose exec app php artisan profiles:fetch madison420ivy    # no key or login needed
```

UI: http://localhost:8000. Horizon: http://localhost:8000/horizon. The UI is a small operations view:
counts (profiles tracked, refreshes pending with the oldest wait, failing now, refreshed in 24 h), a
status per profile with Scout search, and per profile a health verdict, last attempt / success /
failure / next refresh, the attempt timeline and the raw upstream JSON. It updates itself while a
refresh is pending. Only tested on Windows 11 with Docker Desktop.

| Command (in the project folder, on the host) | |
|---|---|
| `docker compose exec app php artisan profiles:fetch madison420ivy [--queue]` | fetch from onlyfans.com now in this process, or hand it to Horizon |
| `docker compose exec app php artisan profiles:fetch madison420ivy --account=fansapi` | the same through Fans API (needs `FANSAPI_KEY` in `.env`) |
| `docker compose exec app php artisan test` | 144 tests (Postgres and Redis of their own, never a real upstream) |
| `docker compose exec -e REFRESH_HANDLER=legacy app php artisan test --group=incident` | the incident tests against the **old** handler: 6 of 7 fail |
| `docker compose exec app php artisan workload:run --handler=legacy` / `--handler=v2` | the workload, before / after |
| `docker compose exec app php artisan workload:run --rotate-rules=4` | the same, with the signing rules changing 4 s in |
| `docker compose exec app php artisan profiles:repair --since="2026-11-01 18:00"` | re-queue profiles written during the incident |
| `docker compose exec app php artisan workload:crash --at=after-write` | kill a worker mid-refresh, watch the redelivery |
| `sh docs/evidence/run-all.sh` | re-create the evidence files (~9 min, local fake upstream only) |
| `sh docs/evidence/live-onlyfans.sh` | the live check against onlyfans.com ([14](docs/evidence/14-live-onlyfans-fetch.txt)) |

**Public demo:** with `PUBLIC_READ_ONLY=true` and `PUBLIC_URL` set, requests that arrive through a
reverse proxy (they carry `X-Forwarded-For`, e.g. via Tailscale Funnel) can only read: no tracking,
no "refresh now", no Horizon dashboard ([`PublicDemo`](app/Http/Middleware/PublicDemo.php)).
Requests made on the machine itself are unaffected.

Services: `app` (UI, Horizon dashboard), `horizon`, `scheduler` (queues due profiles every
minute), `postgres`, `redis` (AOF on), `fake-upstream` (a stand-in for OnlyFans' api2, which checks
signatures the same way; used by the workload). `vendor/` is a named volume: bind-mounted on Docker
Desktop for Windows, every PHP boot took ~6 s (0.4 s now).

## Where the data comes from

**OnlyFans' own api2: `GET https://onlyfans.com/api2/v2/users/{username}`**, the request the web
app makes for a profile page. A public profile needs no login: a guest session is enough.

| Header | Value |
|---|---|
| `sign` | `prefix:sha1:hex(checksum):suffix`, where `sha1` = SHA-1 of `static_param \n time \n path \n user_id` and `checksum` = the sum of the ASCII codes of `sha1` at the rule's `checksum_indexes`, plus `checksum_constant` (absolute value) |
| `time` | the Unix time in ms that was signed |
| `app-token` | from the rules |
| `user-id` | `0` for a guest; the logged-in user's id otherwise (signed too). Not sent when the rules say the web app drops it (`remove_headers`) |
| `x-bc` | a random 40-hex token per session, as the web app keeps in local storage |
| `Cookie`, `User-Agent` | the session's cookies (`sess`, `__cf_bm`, ...) and the user agent they belong to |

What live guest requests showed on 4 Nov ([15](docs/evidence/15-live-probes.txt), before the client
was written): the first signed request works without any cookies, and OnlyFans sets `sess`,
`__cf_bm` and `_cfuvid` on it, so there is no separate session bootstrap; without `x-bc` the answer
is HTTP 400 "Something went wrong"; `user-id` is optional for a guest; an unknown username is HTTP
404 "User not found" (`not_found`); a wrong signature is HTTP 400 with error code 401. Responses
carry `x-of-rev`, the web app's release, which is logged with every rejected signature.

**Signing rules change with OnlyFans releases**, so they are loaded at run time and never
hard-coded ([`SigningRulesProvider`](app/Upstream/OnlyFans/SigningRulesProvider.php)):

- Sources are tried in order (`ONLYFANS_RULES_SOURCES`: four public rule feeds by default, or a local
  JSON file to pin rules by hand). Feeds publish three different shapes; all are read, and the values
  are validated strictly (types, index range 0–39, hex app token), since they come from third
  parties. They are data only: nothing from a feed is executed.
- The rules are cached per host and shared by all workers. After 60 min one worker reloads them
  while the others keep signing with the last good copy; if the sources fail, that copy stays in
  use. A load that finds nothing usable is remembered for 30 s, so jobs fail fast instead of all
  asking the sources again, and no job waits more than 2 s for another worker's load.
- OnlyFans answers a signature it does not accept with HTTP 400 and
  `{"error":{"code":401,"message":"Please refresh the page"}}`. That is `signature_rejected`: the
  cached rules and the guest session are dropped, and the refresh retries soon. A rejection can be
  the session's fault, so what is counted is the *sessions* rejected with a set of rules (a Redis set
  of hashed `x-bc` tokens): two requests in flight on one bad session count once, and a logged-in
  account whose login OnlyFans distrusts cannot condemn rules on its own. Rules rejected for two
  different sessions within 30 min are skipped and the next source's rules are used. If no source
  has usable rules, nothing is sent (`rules_unavailable`, retried later, within the budget).
- On 4 Nov one of the four feeds had current rules and two were outdated (their requests got the
  answer above; [15](docs/evidence/15-live-probes.txt)). A rotation handled mid-run:
  [13](docs/evidence/13-workload-rules-rotation.txt).

**Sessions** ([`SessionStore`](app/Upstream/OnlyFans/SessionStore.php)): one per account, in the
cache (encrypted: cookies are credentials), shared by all workers. A guest session is created on
first use; every response's `Set-Cookie` headers are applied in order (OnlyFans deletes and re-sets
`sess` in one response, without a `Domain`), to the session as it is stored at that moment, under a
short per-account lock: concurrent responses do not undo each other's cookies, and a late response
cannot bring back a session that was dropped meanwhile. After a rejection or a 401/403 a guest
session is dropped and the next request starts a new one. An account can instead hold a logged-in
browser session in its encrypted credential (`{"user_id", "cookie", "x_bc", "user_agent"}`): then its
user id is sent and signed, the session follows the cookies OnlyFans sets from then on, and it is
replaced when the credential changes or removed with it.

**Likes are `favoritedCount`.** OnlyFans returns no revision, so the time the request was sent (ms)
is the revision: of two answers for one profile, the one requested later carries the newer data.
Stored: the main fields as columns and the **whole user object** as `jsonb` in `profiles.raw`.

```text
$ php artisan profiles:fetch madison420ivy          (live, 14-live-onlyfans-fetch.txt)
LIVE_FETCH_OUTPUT
```

**Fans API** (`--account=fansapi`, driver `fansapi`) is still there for comparison: the first
version used only its `GET /api/profiles/{username}`, which returns the same user object under
`data` plus a cache timestamp. A cached snapshot older than 60 min (`FANSAPI_MAX_CACHE_AGE_MINUTES`)
is not a refresh: the job records `cache_too_old` and asks once more with `?fresh=true`
([11](docs/evidence/11-live-fresh-fetch.txt), first version).

## The failure

The incident handler is kept as [`LegacyRefreshProfile`](app/Jobs/LegacyRefreshProfile.php)
(`REFRESH_HANDLER=legacy`, for reproduction only). It reads `likes` from the top level, turns a
missing value into 0 and marks every response as a successful refresh. To reproduce the queue
symptom it also retries the way a lot of code does: 3 tries 1.5 s apart inside the job, sleeping
while it holds a worker. It calls the upstream through the same client as the fixed job, so before
and after are measured against the same requests.

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

- **Validate every response before writing** ([`ProfilePayloadParser`](app/Upstream/ProfilePayloadParser.php)).
  Accepts OnlyFans' user object, the Fans API format, the old top-level `likes` and the new
  `profile.likes`. Likes must be present and a whole number ≥ 0: explicit 0 is valid; missing,
  negative, `"121k"`, `1.5`, `true`, `null` are not; different values in both places are a conflict.
  The incident formats need a revision. Optional fields with a wrong type or a value that would not
  fit the column are skipped and keep their stored value. A follow-up response (Fans API's
  `?fresh=true`) passes the same checks as the first.
- **Only a newer revision writes** ([`ProfileStore`](app/Refresh/ProfileStore.php)): one statement,
  `UPDATE ... WHERE revision IS NULL OR revision < :new`, so of two racing responses only the newer
  can win whatever order the workers commit in. The same revision again is `unchanged` (confirmed,
  nothing rewritten), an older one `stale_ignored`. The same revision with *different* likes does
  write: the upstream says that version has other data, so the stored row is the wrong one (this
  is how zeros the old handler stored at revision 11 get corrected).
- **The write and its attempt row commit together**, and so do a failure and its attempt row: no
  data without its audit record, and no record of a write that did not happen.
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

Each job run is **one upstream attempt** (Fans API's `?fresh=true` follow-up is a second attempt in
the same run). A retry is a `release()` with a delay, never a sleep in the job, so a waiting refresh
holds no worker.

| Upstream result | Outcome | What happens | Tries per refresh |
|---|---|---|---|
| 429 | `rate_limited` | the **whole account** pauses (`Retry-After` if sent, else a randomised exponential delay); the job retries after it | 8 |
| 5xx, incl. an empty 500 | `server_error` | randomised exponential backoff, 5 s base, 60 s cap (rides out ~30 s of errors) | 4 |
| timeout, 408, 504 | `timeout` | same | 4 |
| 400 "Please refresh the page" | `signature_rejected` | rules and session dropped, retried with backoff from 5 s | 3 |
| no usable signing rules | `rules_unavailable` | nothing sent; retried from 60 s | 3 |
| Fans API: old snapshot even with `?fresh=true` | `cache_still_too_old` | not stored; retried from 30 s | 3 |
| 404, 410 / 401, 403 / other 4xx / invalid body | `not_found` / `auth_failed` / `client_error` / `invalid_response` | fails at once, data kept | 1 |

**At most 10 upstream attempts per refresh, checked before every request**, follow-ups included.
"Randomised" is equal jitter: half the delay fixed, half random, so retries neither fire instantly
nor arrive together. There is deliberately no `retryUntil()` and no fixed number of tries
(`$tries = 0`): both also count time spent waiting in the queue, and would fail refreshes that never
reached the upstream exactly when the queue is backed up. Unexpected exceptions, and workers dying on
the job (`#[CountCrashesAsExceptions]`), stop it after 3 (`$maxExceptions`).

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
  The HTTP timeout keeps one slow upstream call well inside it. Signing rules add at most a 2 s wait
  for another worker's load, or a load of their own at 3 s per source: with all four feeds hanging,
  2 + 12 + 10 s = 24 s, still inside the 30 s.

**Two workers on the same long-running attempt:** (1) the nesting above, so redelivery only
happens once the first worker is gone; (2) `WithoutOverlapping` per profile, with a lock that
outlives the job timeout, so a duplicate waits instead of running alongside; (3) the claim check,
so a redelivered job whose refresh already finished does nothing; (4) idempotent writes (revision
guard). For work that legitimately runs longer than `retry_after` I would split it into short jobs
or extend a lease while it runs, rather than raise `retry_after` for everything.

## Before and after

`php artisan workload:run --handler=legacy|v2`, against the local
[fake upstream](fake-upstream/server.php), processed by the running Horizon (4 workers). The fake
speaks OnlyFans' api2 and rejects a request whose signature does not match its rules, so every
request goes through the real client: signing, session, cookies. Every profile starts at likes
120,000 / rev 10; the upstream now answers 121,000 / rev 11, alternating old and new format (a
partial rollout).

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
| Upstream requests (counted by the fake upstream) | 68 | 10 | 54 | 10 |
| **Requests per correct refresh** | **3.24** | **2.0** | **1.35** | **1.0** |
| Upstream answers | 38×200, 26×429, 4×500 | 10×200 | 40×200, 10×429, 4×500 | 10×200 |
| **Oldest waiting refresh (max)** | 16.2 s | **15.5 s** | 30.7 s | **1.5 s** |
| All finished after | 15.7 s | 15.0 s | 30.5 s | 1.4 s |
| Session tokens or keys in logs | 0 | 0 | 0 | 0 |

- **Old:** everything "succeeds" and about half the data is wrong. The calm account cannot start
  until the busy burst has slept through its retries.
- **Fixed:** all 50 correct. The calm account is served while the busy one is rate limited. A 429
  pauses the whole busy account instead of each job hammering on, and its refreshes recover as soon
  as the storm ends.
- The busy account takes *longer* to finish with the fix, because it now respects the limit instead
  of writing zeros. At 5 requests/s after a 6 s storm the floor is ~14 s; the rest is backoff that
  grows with each 429, and the patient 5xx retries (5 s base) for its empty 500s. Pacing each
  account with a token bucket would close most of that gap.
- "Oldest waiting refresh" runs from the moment a refresh is queued until it finishes (applied or
  failed for good); a retry in Redis's delayed set is still waiting.

**Signing rules rotated mid-run** (`--rotate-rules=4`, [13](docs/evidence/13-workload-rules-rotation.txt)):
the same 40 + 10 workload, with the fake upstream switching to new signing rules 4 s in, as OnlyFans
does with a release. One request was rejected ("Please refresh the page"); that worker dropped the
cached rules and its session, the next load got the new rules (fingerprint `aa2a8f26…` →
`ea4fdcf9…`) and every worker used them from then on. Result: 50 of 50 correct, 55 + 10 requests,
the calm account's oldest wait 1.3 s, 0 failed jobs.

## Worker crash

`php artisan workload:crash --at=after-write` makes the Horizon worker that picks up a refresh kill
itself (`SIGKILL`) right after the database commit, before the queue hears the job finished. This is a
local-only test seam ([`ProfileRefresher::crashForTest`](app/Refresh/ProfileRefresher.php)), armed by a
one-shot Redis key. Then the command waits for Redis to hand the job out again
([05](docs/evidence/05-crash-after-write.txt), [06](docs/evidence/06-crash-before-write.txt)).

| | killed after the write | killed before the write |
|---|---|---|
| Right after the kill | data 121,000 / rev 11, claim released, 1 attempt recorded, job still reserved | data unchanged, claim held, no attempt recorded, job reserved |
| Redelivered after | 91 s (`retry_after` 90 s) | 91 s |
| Second delivery | skipped: refresh already finished, no upstream call | did the refresh: `success` |
| Profile rows / failed jobs | 1 / 0 | 1 / 0 |
| Upstream requests / attempts recorded | 1 / 1 | 2 / 1 |

With the write and the attempt row in one transaction, "data written but no attempt recorded"
cannot happen: the after-write kill shows both, the before-write kill neither.

**What this proves:** a worker process that dies mid-job (`SIGKILL`, as the OOM killer sends) loses
neither the refresh nor data integrity; Redis redelivers after `retry_after`, the lock and the
concurrency slot expire on their own, and the second run is harmless. A job that keeps killing its
worker stops after 3 crashes (`#[CountCrashesAsExceptions]`).

**What it does not prove:** losing Redis itself (the reserved job lives in Redis; AOF with
`everysec` can lose up to a second), a crash during the commit, a commit whose acknowledgement never
reached the worker, network partitions, or a frozen rather than dead machine. A worker that dies
between the upstream call and the commit leaves that call unrecorded (the profile is untouched, and
the redelivery makes a new attempt).

## Observability

Every refresh event is one JSON line in `storage/logs/refresh.log` with `refresh_id`, `account`,
`account_id`, `profile_id`, `username`, `job_id`, `job_attempt`, `upstream_attempt`, `outcome`,
`http_status`, `retry_in_seconds`, `duration_ms` (and `format`, `revision`, `likes`,
`memory_peak_mb` on success). Events: `queued`, `deferred` (debug), `retry_scheduled`,
`cache_too_old`, `success` / `unchanged` / `stale_ignored`, `failed`, `gave_up`, `skipped_not_pending`;
for signing, `onlyfans.rules_loaded`, `onlyfans.signature_rejected` (with the rules' source, a short
fingerprint and the rejection count) and `onlyfans.rules_unavailable`. One refresh through a 429
storm and its recovery (`profiles:fetch calm-001`: first attempt in the CLI, retries in Horizon;
real lines, abridged, first version):

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
when the refresh queue waits over 60 s (no notification channel is configured in this project).
**Secrets:** session cookies and `x-bc` live only in the encrypted cache entry and the request
headers; a logged-in credential and the Fans API key are encrypted at rest (or read from
`FANSAPI_KEY`); no header is logged and connection errors are scrubbed. Tests check the logs and the
raw cache for cookie values and `x-bc`, and every workload run checks the logs for the accounts'
session tokens: 0 found.

**Memory:** the highest worker peak seen over 3,000 refreshes was 36.5 MB; workers recycle after
1,000 jobs or an hour (64 MB limit). The scheduler peaks at 30 MB whether it queues 3,000 or 30,000
profiles ([09](docs/evidence/09-scheduler-memory.txt)). Bodies over 1 MB are refused before decoding
(they are still read into memory first; a streaming size cap is not done).

## The next scaling limit

Full write-up: [docs/scaling.md](docs/scaling.md). In short: one machine did **49 refreshes/s with 4
workers** through the signed client (upstream call p50 57 ms), and the scheduler queues **430–900/s**
per process depending on how busy the machine is ([07](docs/evidence/07-throughput.txt),
[09](docs/evidence/09-scheduler-memory.txt)). At 579/s on average, the **scheduler is the first
thing to break**: it barely matches the average and is below any peak. Next come Postgres writes
(3 per refresh, plus `refresh_attempts` at ≥ 50 M rows/day) and, outside our control, OnlyFans' own
throttling per session and per IP, which is not published. **First change:** pipeline each claimed
batch into Redis, log per batch instead of per job, run several scheduler shards (the `SKIP LOCKED`
claim already allows it), and jitter `next_refresh_at`.

## Production plan

**First 15 minutes**

1. *Confirm and size (0–5 min).* Horizon: wait time and throughput per queue, failed jobs. Attempts
   and logs: outcome and HTTP status per account over the last hour. A jump in 429s/500s, in
   `signature_rejected`, or a new response shape points at the upstream, since nothing was deployed.
   Data: `SELECT count(*) FROM profiles WHERE likes_count = 0 AND last_success_at > now() - interval '1 hour'`.
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

**If OnlyFans changes its signing** (all accounts get `signature_rejected`): the client moves to the
next feed on its own; if none is current, `rules_unavailable` stops all requests. To recover by hand,
put the new rules in a JSON file and list it first in `ONLYFANS_RULES_SOURCES`; the next load uses
it (or clear the cached `onlyfans:rules:onlyfans.com` entry for an immediate switch).

## Remaining limits

- **Only tested on Windows 11 + Docker Desktop.** macOS should work; Linux may need the `chmod` above.
  The check from a fresh clone in an empty folder ([10](docs/evidence/10-clean-setup.txt)) was
  recorded on the first version: `setup.sh` and the test suite. Since then `setup.sh` changed only
  its closing message, `.env.example` gained the `ONLYFANS_*` settings (all optional), and the new
  migration and seeder run in every test run; `compose.yaml` and the lockfiles are unchanged.
- **The signing rules come from public feeds**, validated but not derived here. Extracting them from
  OnlyFans' own JavaScript bundle would remove that dependency; not done. If every feed falls behind,
  refreshes stop (`rules_unavailable`) until rules are pinned by hand.
- **OnlyFans' limits per guest session and per IP are unknown**: only a handful of live requests
  were made. 429 and 5xx from OnlyFans were never seen live; their handling is tested against fakes.
- **The logged-in session mode is tested with fakes only.**
- **`favoritedCount` = likes** is the field OnlyFans' api2 returns; I did not compare it with the
  number on the profile page (the browser tool refused to open onlyfans.com).
- **Rate limits are discovered, not paced**, and the gate re-queues waiting jobs instead of holding
  them per account; fine for this workload, churn at tens of thousands. No account-level circuit
  breaker for 5xx or 401/403 yet; each profile backs off on its own.
- **A worker killed between the upstream call and the commit leaves the call unrecorded**, and so
  does an unexpected exception there (bookkeeping after the response, such as saving cookies, is
  caught and logged so it cannot cause one). Such calls are outside the 10-attempt budget, bounded
  by `$maxExceptions` (3).
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
| **First submission** (1 Nov, 20:20–22:20 and 22:28–23:22) | **2:54** |
| *Afterwards (2 Nov, 00:00–00:30):* read-only public demo mode, hosting it through Tailscale Funnel | 0:30 |
| *After the review (4 Nov):* REVIEW_ROW | REVIEW_TIME |

## Layout

```
app/Upstream/       ProfileClient; OnlyFans/ (OnlyFansClient, RequestSigner, SigningRules,
                    SigningRulesProvider, Session, SessionStore); FansApiClient; UpstreamClients
                    (driver → client); ProfilePayloadParser (validation), ProfileSnapshot
app/Refresh/        ProfileRefresher (one attempt), ProfileStore (all writes), RefreshScheduler (claims),
                    Outcome, RefreshPolicy (24 h / 72 h), Backoff, AccountThrottle (cooldowns)
app/Jobs/           RefreshProfile (fixed), LegacyRefreshProfile (incident),
                    Middleware/SkipUnclaimedRefresh, Middleware/AccountGate
app/Console/        profiles:fetch|enqueue-due|repair, workload:run|check|crash|throughput|scheduler-memory
fake-upstream/      stand-in for OnlyFans' api2 (checks signatures, can rotate its rules) and Fans API
resources/fixtures/ upstream response fixtures, incl. real madison420ivy responses, made-up signing rules
tests/              144 PHPUnit tests
docs/               scaling.md; evidence/ (tests, workload, crash, throughput, memory, clean setup, live fetches)
```
