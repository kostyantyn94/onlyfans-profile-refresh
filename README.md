# Profile Refresher — Fans API engineer test task

A small Laravel 13 service that fetches the full OnlyFans profile of
[madison420ivy](https://onlyfans.com/madison420ivy) **straight from onlyfans.com**. It makes the same
signed `api2` request OnlyFans' web app makes, as a guest, with the signing rules read from OnlyFans'
own web app code. Horizon workers keep the profile fresh. The incident from the task is reproduced,
fixed and measured.

```text
$ php artisan profiles:fetch madison420ivy                (live, 9 Nov: docs/evidence/14)
| name                    | Madison Ivy                          |
| upstream id             | 5140520                              |
| likes                   | 612,203                              |
| posts / photos / videos | 178 / 623 / 202                      |
| fields stored (raw)     | 85                                   |
| this attempt            | success, HTTP 200, 332 ms            |
```

**How the request is made** (details: [docs/signing.md](docs/signing.md)):

1. **The request.** `GET https://onlyfans.com/api2/v2/users/madison420ivy` with `sign`, `time`,
   `app-token`, `x-bc` and `user-id`, all built here
   ([`OnlyFansClient`](app/Upstream/OnlyFans/OnlyFansClient.php),
   [`RequestSigner`](app/Upstream/OnlyFans/RequestSigner.php)). The signature is
   `sign = prefix:sha1(static_param \n time \n path \n user_id):hex(checksum):suffix`.
2. **The rules.** The static param, prefix, suffix, checksum and app token are read at run time
   from OnlyFans' web app: the page, `app.js` and the obfuscated signing module. The module is
   decoded as text and never executed
   ([`WebAppRulesExtractor`](app/Upstream/OnlyFans/WebAppRulesExtractor.php);
   [why](docs/signing.md#why-the-code-is-read-not-run)). The rules are kept
   per release, and a response from a newer release reloads them.
3. **The session.** There is one session per account, a guest by default. It holds a random
   `x-bc`, the cookies OnlyFans sets (with their expiry) and the user agent. It is encrypted in the
   cache and shared by all workers.

Nothing else is called at run time. If a release's code cannot be read and no good rules are
cached (a fresh setup), the rules OnlyFans last accepted, kept in the repository
([`last-accepted-rules.json`](resources/onlyfans/last-accepted-rules.json)), are used, with a warning.
Public rule feeds are an opt-in fallback, off by default.

Checked live:
- [16](docs/evidence/16-webapp-rules.txt): the rules read from OnlyFans' release of 9 Nov,
  `202611091510-66895e6664`;
- [14](docs/evidence/14-live-onlyfans-fetch.txt): the fetch signed with them, through the job, in the
  CLI and in Horizon (9 Nov);
- [15](docs/evidence/15-live-probes.txt), section 3: OnlyFans accepting the rules of the release
  before it, `202611031453-fbc506331f` (4 Nov);
- [17](docs/evidence/17-previous-release-rules.txt): OnlyFans still accepting those 3 Nov rules a day
  after the 6 Nov release (7 Nov), which is what the rules kept in the repository rely on.

**Where to look first:**
1. `./setup.sh`, then `docker compose exec app php artisan profiles:fetch madison420ivy`: a live
   signed fetch, no key or login ([Run it](#run-it)).
2. [docs/signing.md](docs/signing.md): the request, where the rules come from, and what happens
   when OnlyFans changes them.
3. [Changes after the review](#changes-after-the-review): each finding, its fix and its regression
   test.
4. [`ProfileRefresher`](app/Refresh/ProfileRefresher.php): one refresh, its attempt budget and its
   transaction.
5. [Before and after](#before-and-after): the incident workload, the old handler against the fixed one.

[Changes after the review](#changes-after-the-review) · [Run it](#run-it) ·
[Data source](#where-the-data-comes-from) · [The failure](#the-failure) ·
[The fix](#the-fix-protect-the-data) · [Queue](#keep-the-queue-moving) ·
[Before and after](#before-and-after) · [Worker crash](#worker-crash) ·
[Observability](#observability) · [Scaling](#the-next-scaling-limit) ·
[Production plan](#production-plan) · [Limits](#remaining-limits) · [Time spent](#time-spent) ·
[Appendix](#appendix-the-public-demo)

## Changes after the review

| Review finding | Change | Tests / evidence |
|---|---|---|
| The integration called the managed Fans API endpoint instead of OnlyFans | Requests are signed and sent to `onlyfans.com/api2/v2/users/{username}` directly, as above. The Fans API client is removed, with its settings and its seeded account (a migration deletes that account from existing databases). | [`OnlyFansClientTest`](tests/Feature/OnlyFansClientTest.php), [`RequestSignerTest`](tests/Unit/RequestSignerTest.php), [`WebAppRulesExtractorTest`](tests/Unit/WebAppRulesExtractorTest.php); live: [14](docs/evidence/14-live-onlyfans-fetch.txt), [15](docs/evidence/15-live-probes.txt), [16](docs/evidence/16-webapp-rules.txt); rules rotation: [13](docs/evidence/13-workload-rules-rotation.txt) |
| The response to the forced-fresh request was not checked for stale data (Fans API cache) | Fixed first in the Fans API client: an old snapshot after `?fresh=true` was not stored ([12](docs/evidence/12-review-fixes-tests.txt), commit `a6d086d`). That client is now removed, with its cache and its `?fresh=true` follow-up: OnlyFans answers with live data, and every response goes through the same checks. | [12](docs/evidence/12-review-fixes-tests.txt) |
| That extra request could exceed the ten-attempt limit | The budget is checked before every call, and each attempt is recorded right before its request goes out, in its own commit. A redelivered job with no attempts left sends nothing, and a call whose worker died before storing the answer still counts (`in_flight`). The extra request itself went with the Fans API client: one request per attempt. | [12](docs/evidence/12-review-fixes-tests.txt), [`RealWorkerTest`](tests/Feature/RealWorkerTest.php), [06](docs/evidence/06-crash-before-write.txt) |
| The profile update and the attempt record were committed separately | The profile write and its attempt's result commit in one transaction, on success and on failure: a crash leaves both or neither. | [12](docs/evidence/12-review-fixes-tests.txt) |

The stale-response and extra-request findings were about the Fans API cache (`?fresh=true`).
OnlyFans' api2 has no cache, so every request is one attempt with live data. All four regression
tests for the three findings failed on the reviewed code (recorded with the fix stashed) and passed
with the fix ([12](docs/evidence/12-review-fixes-tests.txt)); the two about `?fresh=true` went with
the Fans API client, the other two still run. Two older tests had only passed because of the first
finding; see [AI.md](AI.md).

**The incident, same workload before and after** ([details](#before-and-after)):

| | Old handler | Fixed handler |
|---|---|---|
| Refreshes the workers reported as successful | 50 of 50 | 50 of 50 |
| Refreshes with **correct stored data** | 26 of 50 (24 have likes = 0) | **50 of 50** |
| Busy account: upstream requests per correct refresh | 3.38 | 1.38 |
| Busy account: all its refreshes finished after | 17.0 s, half of them wrong | 26.6 s: it now waits out the rate limit |
| Calm account: oldest waiting refresh | 16.3 s (stuck behind the busy account) | 1.9 s |

After the old handler's run, `profiles:repair` and the fixed handler brought all 50 profiles back
to correct data ([03b](docs/evidence/03b-repair-after-legacy.txt)).

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
| `docker compose exec app php artisan onlyfans:rules [--reload]` | the signing rules in use, read from OnlyFans' web app (no API request) |
| `docker compose exec app php artisan test` | 168 tests (Postgres and Redis of their own, never a real upstream) |
| `docker compose exec -e REFRESH_HANDLER=legacy app php artisan test --group=incident` | the incident tests against the **old** handler: 6 of 7 fail |
| `docker compose exec app php artisan workload:run --handler=legacy` / `--handler=v2` | the workload, before / after |
| `docker compose exec app php artisan workload:run --rotate-rules=4` | the same, with the signing rules changing 4 s in |
| `docker compose exec app php artisan profiles:repair --since="2026-11-01 18:00"` | re-queue profiles written during the incident |
| `docker compose exec app php artisan workload:crash --at=after-write` | kill a worker mid-refresh, watch the redelivery |
| `sh docs/evidence/run-all.sh` | re-create the evidence files (~6 min, local fake upstream only) |
| `sh docs/evidence/live-onlyfans.sh` | the live check against onlyfans.com ([14](docs/evidence/14-live-onlyfans-fetch.txt)) |
| `sh docs/evidence/webapp-rules.sh` | the signing rules read live from OnlyFans' web app, next to the public feeds ([16](docs/evidence/16-webapp-rules.txt)) |

Services: `app` (UI, Horizon dashboard; port 8000 on this machine only), `horizon`, `scheduler`
(queues due profiles every minute), `postgres`, `redis` (AOF on), `fake-upstream` (a stand-in for
OnlyFans' api2, which checks signatures the same way; used by the workload). `vendor/` is a named
volume: bind-mounted on Docker Desktop for Windows, every PHP boot took ~6 s (0.4 s now).

## Where the data comes from

**OnlyFans' own api2, `GET https://onlyfans.com/api2/v2/users/{username}`**: the request the web app
makes for a profile page. A public profile needs no login; a guest session is enough. The headers,
the rules and the session are described in [docs/signing.md](docs/signing.md). In short:

- **Signing rules** come from OnlyFans' web app, read per release
  ([`WebAppRulesSource`](app/Upstream/OnlyFans/WebAppRulesSource.php)).
  - They are cached per host and shared by all workers. After 60 min one worker reloads them while
    the others keep signing.
  - A response from a newer release (`x-of-rev`) reloads them at once.
  - If the web app's code changes shape, the extractor fails loudly. It never guesses: the last
    good rules stay in use, or on a fresh setup the rules OnlyFans last accepted, kept in the
    repository. `onlyfans.rules_loaded` is then a warning that says why the web app was passed over.
- **A refused signature** (HTTP 400, `"Please refresh the page"`) is `signature_rejected`. The rules
  are reloaded, the guest session is renewed and the refresh retries soon.
  - Rejections are counted per *session*, in a fixed 30-minute window. Rules OnlyFans accepted in
    the last 10 minutes are not counted against at all, so a few distrusted sessions cannot switch
    off good rules.
  - Rules rejected for two different sessions give way to the next source, if one is configured.
    The last source is never skipped: sending nothing would stop every refresh with no request left
    to prove the rules good again. Each refresh is still bounded by its `signature_rejected` tries.
- **The session's cookies** are applied from every response under a per-account lock, kept with
  their expiry, and encrypted at rest.

**Likes are `favoritedCount`.** OnlyFans returns no revision, so the time the request was sent (ms)
is the revision: of two answers for one profile, the one requested later carries the newer data.
Stored: the main fields as columns and the **whole user object (85 fields)** as `jsonb` in
`profiles.raw`; the captured response is a fixture,
[`onlyfans-madison420ivy.json`](resources/fixtures/upstream/onlyfans-madison420ivy.json). The same
fetch through Horizon: `profiles:fetch madison420ivy --queue` → success, HTTP 200, 241 ms
([14](docs/evidence/14-live-onlyfans-fetch.txt)).

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
  Accepts OnlyFans' user object, the old top-level `likes` and the new `profile.likes`. Likes must be present and a whole number ≥ 0: explicit 0 is valid; missing,
  negative, `"121k"`, `1.5`, `true`, `null` are not; different values in both places are a conflict.
  The incident formats need a revision. Optional fields with a wrong type or a value that would not
  fit the column are skipped and keep their stored value.
- **The answer must be the profile asked for.** An OnlyFans answer for another username,
  or with another upstream id than the one stored (the username now belongs to someone else), is
  `invalid_response` and writes nothing.
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

Each job run is **one upstream attempt**. A retry is a `release()` with a delay, never a sleep in the job, so a waiting refresh
holds no worker.

| Upstream result | Outcome | What happens | Tries per refresh |
|---|---|---|---|
| 429 | `rate_limited` | the **whole account** pauses (`Retry-After` if sent, else a randomised exponential delay); the job retries after it | 8 |
| 5xx, incl. an empty 500 | `server_error` | randomised exponential backoff, 5 s base, 60 s cap (rides out ~30 s of errors) | 4 |
| timeout, 408, 504 | `timeout` | same | 4 |
| 400 "Please refresh the page" | `signature_rejected` | rules marked for reload, guest session renewed, retried with backoff from 5 s | 3 |
| no source has valid signing rules (and none are cached) | `rules_unavailable` | nothing sent; retried from 60 s | 3 |
| another worker is loading the rules | (deferred) | nothing sent, no attempt spent; tried again in 3 s | – |
| 404, 410 / 401, 403 / other 4xx / invalid body | `not_found` / `auth_failed` / `client_error` / `invalid_response` | fails at once, data kept | 1 |

**At most 10 upstream attempts per refresh, checked before every request**.
Each attempt row is written before its request is sent, in its own commit, and completed with the
result: a call whose worker dies before storing the answer stays `in_flight` and still counts, so
the limit holds even across crashes. "Randomised" is equal jitter: half the delay fixed, half random,
so retries neither fire instantly nor arrive together. There is deliberately no `retryUntil()` and no
fixed number of tries (`$tries = 0`): both also count time spent waiting in the queue, and would fail
refreshes that never reached the upstream exactly when the queue is backed up. Unexpected exceptions,
and workers dying on the job (`#[CountCrashesAsExceptions]`), stop it after 3 (`$maxExceptions`),
10 s and then 60 s apart (`$backoff`), so a short database failover does not use them up at once.

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
  for another worker's load (after which the job is deferred), or a load of their own capped at 12 s
  over all sources (each download at most 5 s): 2 + 12 + 10 s = 24 s, still inside the 30 s.

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
| Upstream requests (counted by the fake upstream) | 71 | 10 | 55 | 10 |
| **Requests per correct refresh** | **3.38** | **2.0** | **1.38** | **1.0** |
| Upstream answers | 35×200, 34×429, 2×500 | 10×200 | 40×200, 10×429, 5×500 | 10×200 |
| **Oldest waiting refresh (max)** | 17.5 s | **16.3 s** | 27.1 s | **1.9 s** |
| All finished after | 17.0 s | 15.7 s | 26.6 s | 1.5 s |
| Session tokens or keys in logs (every one the fake upstream saw) | 0 | 0 | 0 | 0 |

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
does with a release. One request was rejected ("Please refresh the page"). The rejection marked the
cached rules for reloading and renewed the session; 10 ms later the new rules were loaded
(fingerprint `aa2a8f26…` → `ea4fdcf9…`) and every worker used them from then on. Result: 50 of 50
correct, 49 + 10 requests, the calm account's oldest wait 1.6 s, 0 failed jobs. (The rules had been
accepted seconds before, so the rejection was not counted against them; the reload found new ones.)

## Worker crash

`php artisan workload:crash --at=after-write` makes the Horizon worker that picks up a refresh kill
itself (`SIGKILL`) right after the database commit, before the queue hears the job finished. This is a
local-only test seam ([`ProfileRefresher::crashForTest`](app/Refresh/ProfileRefresher.php)), armed by a
one-shot Redis key. Then the command waits for Redis to hand the job out again
([05](docs/evidence/05-crash-after-write.txt), [06](docs/evidence/06-crash-before-write.txt)).

| | killed after the write | killed before the write |
|---|---|---|
| Right after the kill | data 121,000 / rev 11, claim released, attempt `success`, job still reserved | data unchanged, claim held, attempt `in_flight`, job reserved |
| Redelivered after | 91 s (`retry_after` 90 s) | 91 s |
| Second delivery | skipped: refresh already finished, no upstream call | did the refresh: attempts `in_flight`, `success` |
| Profile rows / failed jobs | 1 / 0 | 1 / 0 |
| Upstream requests / attempts recorded | 1 / 1 | 2 / 2 |

With the write and the attempt's result in one transaction, "data written but no attempt recorded"
cannot happen: the after-write kill shows both. The before-write kill shows the call counted
(`in_flight`) and nothing written; the redelivery's call is the second of the 10 allowed.

**What this proves:** a worker process that dies mid-job (`SIGKILL`, as the OOM killer sends) loses
neither the refresh nor data integrity; Redis redelivers after `retry_after`, the lock and the
concurrency slot expire on their own, and the second run is harmless. A job that keeps killing its
worker stops after 3 crashes (`#[CountCrashesAsExceptions]`).

**What it does not prove:** losing Redis itself (the reserved job lives in Redis; AOF with
`everysec` can lose up to a second), a crash during the commit, a commit whose acknowledgement never
reached the worker, network partitions, or a frozen rather than dead machine.

## Observability

Every refresh event is one JSON line in `storage/logs/refresh.log` with `refresh_id`, `profile_id`
and `account_id`; the lines of an attempt add `account`, `username`, `job_id`, `job_attempt`,
`upstream_attempt`, `outcome`, `http_status`, `retry_in_seconds`, `duration_ms` (and `format`,
`revision`, `likes`, `memory_peak_mb` on success). Events: `queued`, `deferred` (debug), `retry_scheduled`,
`success` / `unchanged` / `stale_ignored`, `failed`, `gave_up`, `skipped_not_pending`;
for signing:
- `onlyfans.rules_loaded`: the source, the release and the sources passed over, with the reason;
- `onlyfans.webapp_rules_read`: the release, the script and the time taken;
- `onlyfans.signature_rejected`: the rules' source, release and short fingerprint, OnlyFans' current
  release, whether the rules were accepted recently (`rules_proven`) and the sessions rejected;
- `onlyfans.rules_unavailable`, with each source's problem.

`php artisan onlyfans:rules [--reload]` shows the rules in use and where they came from. One
refresh of the rules rotation run ([13](docs/evidence/13-workload-rules-rotation.txt)), through a
429, the account's cooldown and a rejected signature, with the signing events around it (real
lines, abridged; the attempt lines also carry `account`, `username`, `job_id`):

```text
$ grep 98c44981-aa75-4e5f-83ec-ae5ffe0ea586 storage/logs/refresh.log   (+ the onlyfans.* lines)
03:56:01.02 refresh.queued
03:56:01.20 onlyfans.rules_loaded        rules=aa2a8f263e64 rules_source=http://fake-upstream:8080/rules.json
03:56:01.27 refresh.deferred             reason=account_cooldown delay_seconds=2
03:56:03.82 refresh.retry_scheduled      job_attempt=2 upstream_attempt=1 outcome=rate_limited http_status=429 retry_in_seconds=2
03:56:05.93 refresh.deferred             reason=account_cooldown delay_seconds=2
03:56:07.95 onlyfans.signature_rejected  rules=aa2a8f263e64 rules_proven=true sessions_rejected=0 skipped=false
03:56:07.95 refresh.retry_scheduled      job_attempt=4 upstream_attempt=2 outcome=signature_rejected http_status=400 retry_in_seconds=4
03:56:07.96 onlyfans.rules_loaded        rules=ea4fdcf9fa3d
03:56:11.50 refresh.deferred             reason=account_cooldown delay_seconds=2
03:56:14.19 refresh.success              job_attempt=6 upstream_attempt=3 http_status=200 revision=11 likes=121000
```

Six job runs, three upstream attempts: deferrals spend none.

The same refresh is in `refresh_attempts` (one row per upstream request, written before it is
sent; `in_flight` if its worker died before the answer was stored) and on the profile page;
Horizon jobs are tagged `profile:`, `account:`, `refresh:`, and Horizon raises `LongWaitDetected`
when the refresh queue waits over 60 s (no notification channel is configured in this project).
**Secrets:** session cookies and `x-bc` live only in the encrypted cache entry and the request
headers; a logged-in credential is encrypted at rest; no header is logged and connection errors are scrubbed. Tests check the logs and the
raw cache for cookie values and `x-bc`, and every workload run checks the logs for the accounts'
session tokens: 0 found.

**Memory:** the highest worker peak seen over 3,000 refreshes was 36.5 MB; workers recycle after
1,000 jobs or an hour (64 MB limit). The scheduler peaks at 30 MB whether it queues 3,000 or 30,000
profiles ([09](docs/evidence/09-scheduler-memory.txt)). Bodies over 1 MB are refused before decoding
(they are still read into memory first; a streaming size cap is not done).

## The next scaling limit

Full write-up: [docs/scaling.md](docs/scaling.md). Measured on one machine: **47 refreshes/s with
4 workers** through the signed client (upstream call p50 57 ms), and the scheduler queues
**650–970/s** per process ([07](docs/evidence/07-throughput.txt),
[09](docs/evidence/09-scheduler-memory.txt)). 50 M jobs a day is 579/s on average.

- **First limit: OnlyFans' capacity per identity.** Every request goes out as some session from some
  IP, and OnlyFans limits both (not published, not reached by a handful of live requests). At *r*
  requests/s per guest session, 579/s needs 579 / *r* sessions over enough egress IPs: about 600 at
  1/s, about 60 at 10/s. This service runs one session per account from one IP.
- **Inside our system: the scheduler.** It barely matches the average and is below any peak. Next
  come Postgres writes (4 per refresh, `refresh_attempts` at ≥ 50 M rows/day) and the gate's
  re-queueing of throttled work.

**First change:** measure the sustained rate per session and per IP, then give each account a paced
pool of sessions and IPs. In parallel, pipeline each claimed batch into Redis with one log line per
batch, run several scheduler shards (the `SKIP LOCKED` claim allows it), and jitter
`next_refresh_at`.

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

**If OnlyFans changes its signing** (accounts get `signature_rejected`):
- **New release.** The first response from the new release (`x-of-rev`), or a rejection, marks the
  rules for reloading, so the next load reads the new release's code.
- **Unreadable code.** If that code cannot be read, `onlyfans.rules_loaded` (a warning) or
  `onlyfans.rules_unavailable` says why. The last good rules stay in use while OnlyFans still accepts
  them (on 7 Nov it still accepted the 3 Nov release's, [17](docs/evidence/17-previous-release-rules.txt));
  a fresh setup uses the rules OnlyFans last accepted, kept in the repository.
- **Old rules rejected.** Once OnlyFans rejects them too, every refresh ends in
  `signature_rejected` after its 3 tries and is retried later; `onlyfans.signature_rejected` and the
  failing-now count on the dashboard show it.

To recover by hand, either adjust the extractor to the new code shape, or put the new rules in a
JSON file and list it first in `ONLYFANS_RULES_SOURCES` (a public feed there works as a stopgap).
`php artisan onlyfans:rules --reload` loads them at once and shows what is in use. Once new rules
have been accepted live, they replace those in `resources/onlyfans/last-accepted-rules.json`.

## Remaining limits

- **Only tested on Windows 11 + Docker Desktop.** macOS should work; on Linux, `setup.sh` makes
  `storage/`, `bootstrap/cache` and `.env` writable for the containers' user, which may not be enough
  on every setup.
  A fresh clone in an empty folder at the final code ([10](docs/evidence/10-clean-setup.txt)):
  `setup.sh` (it seeds the `onlyfans` account only), the test suite, the rules read from OnlyFans'
  web app, the UI answering. It ran as a
  second stack next to the first, with the base URL on the fake upstream so that it made no API
  request to onlyfans.com.
- **The rules extraction follows the obfuscator's output shape**: a string table, the rotation
  loop, wrapper functions, a helper object and the checksum as a sum of characters. It reads three
  real releases, of 3, 6 and 9 Nov, and synthetic code of all three shapes, which runs in Node and
  signs as its rules say. Each new release so far changed the shape in small ways (6 Nov: statement
  order, argument order, what follows the helper object; 9 Nov: constants decoded in place, wrappers
  passing the key first). Each time the extractor failed loudly, as designed, the last good rules
  stayed in use, and it was adjusted the same day ([docs/signing.md](docs/signing.md)). The next
  such change will need the same. A digest other than SHA-1 would fail loudly too.
- **How long OnlyFans accepts an earlier release's rules is unknown.** On 4 Nov it accepted at least
  one earlier set; on 7 Nov, a day after the 6 Nov release, it still accepted the 3 Nov rules
  ([17](docs/evidence/17-previous-release-rules.txt)). The fallback to the rules kept in the
  repository lasts as long as that does.
- **The revision of an OnlyFans answer is the local time the request was sent**, so workers on
  different machines need synchronised clocks; a skew larger than the time between two refreshes of
  one profile could order their answers wrongly.
- **OnlyFans' limits per guest session and per IP are unknown**: only a handful of live requests
  were made. 429 and 5xx from OnlyFans were never seen live; their handling is tested against fakes.
- **The logged-in session mode is tested with fakes only.**
- **`favoritedCount` = likes** is the field OnlyFans' api2 returns; I did not compare it with the
  number shown on the profile page.
- **Rate limits are discovered, not paced**, and the gate re-queues waiting jobs instead of holding
  them per account; fine for this workload, churn at tens of thousands. No account-level circuit
  breaker for 5xx or 401/403 yet; each profile backs off on its own.
- **A worker killed between the upstream call and the commit loses that call's answer**: the attempt
  stays `in_flight` (counted, so the budget holds), the profile is untouched, and the redelivery
  makes the next attempt. Bookkeeping after the response, such as saving cookies, is caught and
  logged, so it cannot cause this on its own.
- **Claims lost in a long database outage**: a job that fails for good while the database is down
  cannot give its claim back; the claim expires after 6 h and the profile is queued again.
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
| *After the review (4 Nov, 01:40–03:05):* direct signed OnlyFans client (signing, rules, sessions), the three fixes with regression tests, live probes and the live check, fake upstream with signature checks and rules rotation, a third independent review and its fixes, evidence, docs, packaging | 1:25 |
| *Then (4 Nov, 03:10–06:20):* the signing rules read from OnlyFans' own web app code (extractor, synthetic web app and its Node check, live extraction and probe), the fourth and fifth independent reviews (five reviewers) and their fixes, evidence re-recorded at the final code, clean-setup check, docs, packaging | 3:10 |
| *Readiness check (6–7 Nov: 23:50–00:05, 02:37–02:42, 06:04–06:14, 07:51–08:00):* OnlyFans' new release of 6 Nov read (the extractor adjusted to its shape, the synthetic web app and tests extended), the live check repeated on it, the rules OnlyFans last accepted kept as the last source and checked live, evidence, docs, packaging | 0:39 |
| *8 Nov, 01:15–01:35:* the Fans API client removed (client, cache rule, settings, fixture, its tests; a migration for existing databases), docs, evidence, packaging | 0:20 |
| **Total, both rounds** (the public demo not counted) | **8:28** |

## Layout

```
app/Upstream/       ProfileClient; OnlyFans/ (OnlyFansClient, RequestSigner, SigningRules,
                    SigningRulesProvider, WebAppRulesSource, WebAppRulesExtractor, JsExpression,
                    Session, SessionStore); UpstreamClients
                    (driver → client); ProfilePayloadParser (validation), ProfileSnapshot
app/Refresh/        ProfileRefresher (one attempt), ProfileStore (all writes), RefreshScheduler (claims),
                    Outcome, RefreshPolicy (24 h / 72 h), Backoff, AccountThrottle (cooldowns)
app/Jobs/           RefreshProfile (fixed), LegacyRefreshProfile (incident),
                    Middleware/SkipUnclaimedRefresh, Middleware/AccountGate
app/Console/        profiles:fetch|enqueue-due|repair, onlyfans:rules,
                    workload:run|check|crash|throughput|scheduler-memory
fake-upstream/      stand-in for OnlyFans' api2 (checks signatures, can rotate its rules)
resources/fixtures/ upstream response fixtures, incl. real madison420ivy responses, made-up signing rules
resources/onlyfans/ the signing rules OnlyFans last accepted: the last default source
tests/              168 PHPUnit tests; Support/SyntheticWebApp builds obfuscated signing code from
                    made-up rules (no OnlyFans code is in the repository)
docs/               signing.md, scaling.md; evidence/ (tests, workload, crash, throughput, memory, clean setup, live fetches)
```

## Appendix: the public demo

With `PUBLIC_READ_ONLY=true` and `PUBLIC_URL` set, requests that arrive through a
reverse proxy on the same machine (they carry `X-Forwarded-For`, e.g. via Tailscale Funnel) can only
read: no tracking, no "refresh now", no Horizon dashboard
([`PublicDemo`](app/Http/Middleware/PublicDemo.php)). The app's port is published on 127.0.0.1
only, so nothing reaches it from the network except through that proxy.
