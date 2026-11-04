# How AI was used

## Tools

- **Claude Code** (Anthropic, model Claude Opus 5.5) as an agent in VS Code, with shell, Docker
  and browser access. It read the task and the Fans API docs, proposed the plan and the data
  source, wrote the code, tests, workload and these documents, ran everything and recorded the
  evidence. Commits carry a `Co-Authored-By` line for that reason.
- Further Claude Code agents did **independent reviews** of the finished code, with no access to
  how it was written (prompt: find correctness bugs, unsupported claims, missing requirements,
  vacuous tests): two for the first submission, one after your review. Their findings and what was
  done with them are below.
- A further agent redesigned the UI in a separate git worktree (Blade and plain CSS, no build step);
  I merged and checked it.
- My part: setting the constraints (time box, English-only deliverables, no keys in git, ask
  before system changes), approving the plan and the data source, giving access to my Fans API
  account (the agent created a read-only key in my logged-in browser session and saved it from the
  clipboard straight into a local file outside the repo), and reviewing the results.
- No other AI tools. Documentation used: docs.onlyfansapi.com (a local copy from the previous
  stage) and the Laravel/Horizon/Scout source in `vendor/` when behaviour was unclear.

## What was checked, and how

- **Live data from onlyfans.com** (second round): signed guest requests for madison420ivy through
  the full job path, in the CLI and through Horizon, both HTTP 200 and 612,018 likes, the stored row
  checked against the 85-field response ([14](docs/evidence/14-live-onlyfans-fetch.txt)). The
  signature is also checked against fixed values computed separately in Python, and the fake
  upstream verifies it with its own implementation on every workload request.
- **Live data (first version, Fans API)**: a real `GET /api/profiles/madison420ivy` through the full job path, then through
  Horizon; the stored row compared with the raw response (87 fields). Later a live run showed the
  stale-cache rule working: Fans API's cache was 12 h old (611,905 likes), the job asked again with
  `?fresh=true` and stored 611,954 ([11](docs/evidence/11-live-fresh-fetch.txt), 1 credit).
- **The failure before the fix**: the incident tests were committed with only the old handler in
  the tree (commit `83496a9`, [01](docs/evidence/01-incident-tests-before-fix.txt)) and fail 6 of 7
  there; at the current commit they pass with the fix and still fail 6 of 7 with
  `REFRESH_HANDLER=legacy` ([01b](docs/evidence/01b-incident-tests-legacy-now.txt)).
- **Real worker path**: besides calling `handle()` directly, [`RealWorkerTest`](tests/Feature/RealWorkerTest.php)
  pushes jobs through Redis and runs `queue:work --once`, so the gate, the per-profile lock,
  `release()` and Redis's delayed set are the real ones.
- **Workload numbers** come from two independent counters: the fake upstream counts every request
  by status, the app records every attempt. Stored data is checked row by row, not inferred from
  "job completed".
- **Worker crash**: a real `SIGKILL` of a Horizon worker, after and before the database write,
  waiting for the real `retry_after` redelivery.
- **Secrets**: a test and every workload run search the logs for the API keys (0 found); a
  pre-commit hook refuses `.env` and anything that looks like an `ofapi_` key. The submission ZIP
  is built from a fresh clone, so the local `.env` with the key is not in it.
- **Clean setup**: a fresh clone in an empty folder, `setup.sh`, then the test suite and a keyless
  `profiles:fetch` (recorded as `auth_failed`, HTTP 401):
  [10](docs/evidence/10-clean-setup.txt).

## Incorrect suggestions and mistakes caught

From my own runs:

- **A shell edit stripped every quote from `config/horizon.php`**, leaving invalid PHP. Caught by
  `php -l`; the setup commit was redone with the fixed file.
- **Another scripted edit emptied `RefreshScheduler.php`** (a failed regex returned `null`, which
  was written back). Caught by checking file sizes; restored from a copy made just before. After
  that, multi-line edits went through small scripts that refuse to write unless the anchor occurs
  exactly once.
- **Building a PHP image from `php:8.4-cli-alpine`** stalled on a ~250 KB/s Alpine mirror (still
  installing a compiler after 15 minutes); replaced with a prebuilt image.
- **`busybox cp -an` silently copied nothing**; noticed because `composer.lock` was missing.
- **`pagination::simple-default`** does not exist in Laravel 13; the page returned 500. Caught by
  requesting every page with `curl`.
- **`$job->job->uuid()`** would break with Laravel's `FakeJob`; switched to `getJobId()` after
  reading the framework source.
- **A loop of `Http::fake()` calls in one test** would keep answering with the first fake;
  rewritten as a data provider.
- **`horizon:pause` from the `app` container does nothing** (it signals the Horizon master by PID,
  in another container). The memory check now sends its jobs to a queue no worker listens on.
- **Re-fetching did not repair rows the old handler broke**: it stored likes 0 with the real
  revision 11, which the revision guard treated as "unchanged". Added `profiles:repair`; after the
  second review, the same revision with different likes now also rewrites the row.
- **The scheduler container could claim workload profiles first**, mixing handlers in the "before"
  run; workload profiles are now created as not due, and the evidence script stops the scheduler.
- **`profiles:fetch` in sync mode left a profile claimed after a 429**; the retry now goes to Horizon.
- **`setup.sh` failed under Git Bash on Windows** (`/app/vendor` rewritten to a Windows path), found
  by the clean-clone run.

From the first independent review (all confirmed by reading the code or a test, then fixed):

- **`retryUntil()` was fixed at dispatch**, so in a backlog longer than 15 minutes, the incident's
  own symptom, every refresh would give up at pickup without calling the upstream. Removed;
  retries are bounded by the per-outcome budgets only.
- Removing it exposed the next bug: **a plain `queue:work` defaults to one try** and failed every
  retry. Caught by the new real-worker test; the job now sets `$tries = 0` itself.
- **The last 429 of a refresh did not pause the account**, and the cooldown's read-then-write could
  shorten it under a race. Now every 429 pauses, through one atomic Lua script.
- **A stale response left a due profile due**, so it would be re-queued every minute.
- **Final failures retried hourly forever**; now 1 h doubling to 72 h.
- **The scheduler's default cap (10,000 per minute = 167/s) was below the 579/s target**, and its
  index did not match its sort; the README overstated it. Split into two indexed queries, cap raised,
  README corrected.
- **`timestamp(0)` rounded queue ages up to a second**; `refresh_queued_at` keeps milliseconds now.
- **Optional fields could overflow their columns** and fail every refresh of that profile.
- **README claims without evidence files** (scheduler memory, the legacy run at the current commit,
  the clean setup) now have them, and an anecdotal number was removed.
- Not changed: the review also suggested a per-account circuit breaker for 401/403 and reading
  `_meta._rate_limits.remaining_minute` to pace requests; both are listed as next steps.

From the second independent review, on the code after those fixes (fewer findings, two of them
still serious; all confirmed, fixed unless noted):

- **The claim expired after 30 minutes and was never renewed**, so a backlog longer than that (again
  the incident's own symptom) queued a second job for profiles that already had one, and the old
  job was only skipped after passing the account gate. Claims now expire after 6 hours, and a job
  whose refresh no longer holds the claim is dropped before the gate.
- **The failure backoff counted attempts, not refreshes**: one refresh with 8 × 429 parked a popular
  profile for 72 h. `consecutive_failures` now counts failed refreshes. The test that hid this
  (it seeded attempt rows without the counter) was replaced with a real 8 × 429 sequence.
- **A Fans API cache hit counted as a fresh refresh**, even when the snapshot was 12 h old: the
  incident's "reported success, stale data" built in. A snapshot older than 60 min is now recorded as
  `cache_too_old` and fetched again with `?fresh=true`; checked live.
- **5xx/timeout retries lasted only 3–6 s**; now 4 tries from a 5 s base (~30 s).
- **Crash loops had no limit**; `#[CountCrashesAsExceptions]` now counts a dead worker towards
  `$maxExceptions`.
- **Funnel slot keys could collide** between accounts (no separator before Laravel's slot number);
  the scheduler's overlap lock defaulted to 24 h; lost claims could crowd out due profiles.
- **Claims without support**: Horizon "alerts" (no notification channel is configured; reworded),
  a "test" of two schedulers that was an accidental run (reworded), "worker memory stays there"
  (only the maximum was recorded; reworded), commands in a table that could not take its prefix,
  stale comments about `retryUntil()`, the 7th incident test changed after `01` was recorded
  (noted in the README).
- Not changed, listed as limits: the gate re-queues throttled work into the shared queue (churn at
  tens of thousands of jobs; per-account queues or scheduler-side admission would fix it), no
  account-level circuit breaker for 5xx, and the 1 MB body cap does not stop the body being read.

## Second round, after your review (4 Nov)

Same tool, same way of working: Claude Code wrote the OnlyFans client, the fixes, the tests and
these notes; I set the direction and ran the live checks.

- **The live requests to onlyfans.com were run by me, not the agent.** Claude Code's own safety
  check refused to let the agent send signed requests to onlyfans.com. So the agent wrote two small
  probe scripts (which rule feed is current, which headers and cookies a guest request needs, what
  a bad signature and an unknown user get), I ran them and pasted the output back, and the client
  was built from that ([15](docs/evidence/15-live-probes.txt),
  [`probe-onlyfans.py`](docs/evidence/probe-onlyfans.py)). The full live check through the job is
  [14](docs/evidence/14-live-onlyfans-fetch.txt), also run by me.
- **Each of your three findings has a regression test that fails on the reviewed code**, recorded
  with the fix stashed: [12](docs/evidence/12-review-fixes-tests.txt).

Mistakes caught in this round:

- **Two existing tests only passed because of your first finding.** They fed a Fans API snapshot
  cached at 08:12 to a refresh running hours later; the refresh asked again with `?fresh=true`, got
  the same old snapshot and stored it as a success. With the fix they failed; they now run at a time
  shortly after the snapshot, so they test what they were written for.
- **Guzzle's `SetCookie::validate()` rejects a cookie without a `Domain`**, and OnlyFans sets its
  `sess` cookie without one: the session would have silently lost its cookie. The client checks
  names and values itself instead.
- **The rules format `"prefix:{}:{:x}:suffix"` has a colon inside `{:x}`.** Splitting it into four
  parts gave the wrong suffix, in the first probe script and in the fake upstream. The fake one was
  caught by a workload in which every signature was rejected; that run also showed the protection
  working: after two rejections of the same rules the client stopped sending (2 requests in total,
  every refresh recorded `rules_unavailable`).
- **The workload report undercounted "reported successful"**: `last_success_at` has whole seconds
  and was compared with a start time that has microseconds, so refreshes finished in the first
  second were missed. Fixed in the report; the stored data was right.
- **`php artisan migrate --env=testing` ran against the local database**, because there is no
  `.env.testing`. Harmless here (it was the new migration, which sets every existing account to the
  Fans API driver), but the tests migrate their own database and that command was not needed.

A third independent review (same setup as the first two, of this round's code) found, all confirmed
and fixed, each with a new test:

- **One bad session could switch off the current rules for every account.** Rejections were counted
  per response: two requests in flight on one distrusted session, or a logged-in account whose
  login was no longer trusted, were enough to skip valid rules for 30 min. Now the *sessions* are
  counted (a Redis set of hashed `x-bc` tokens), so it takes two different sessions.
- **Rules loading could hold workers and hammer the feeds** while no rules were usable: no memory of
  a failed load, a 10 s lock wait, 4 s per feed, together past the 30 s job timeout. Now a failed
  load is remembered for 30 s, stale rules keep being used while one worker reloads (and when the
  feeds fail), the lock wait is 2 s and a feed gets 3 s.
- **Concurrent responses could overwrite the session**, or bring back one that had just been
  dropped after a rejection. Updates now apply to the stored session under a per-account lock, and
  only if it is still the session the request was sent with.
- **Removing a login credential was not honoured** (the stored logged-in session stayed), and a
  logged-in session lost its rotated cookies on a rejected signature. Both fixed.
- **The stale check trusted `is_cached`**: a `?fresh=true` answer marked live but taken hours ago
  would have been stored. The age now comes from `cached_at` alone.
- Not changed: the old handler now fails its job when no signing rules are usable (the incident
  handler had no such case; the incident tests are unaffected), and calls made right before a crash
  stay unrecorded (listed in the README's limits).

## Not verified

- That `favoritedCount` is the "likes" number OnlyFans shows on the profile page: it is the field
  OnlyFans' own api2 returns and Fans API passes on, but the browser tool refused to open
  onlyfans.com, so the page itself was not compared.
- How long a guest session stays valid, and how many requests OnlyFans allows per session or per
  IP before it throttles: only a handful of live requests were made. 429 and 5xx from OnlyFans were
  never seen live; their handling is tested against fakes.
- Which public rule feed stays current: on 4 Nov one of the four (rafa-9) was, two were outdated,
  one was not read because of the format bug above. The client tries them in order and moves on
  after repeated rejections; extracting the rules from OnlyFans' own JavaScript is not done.
- The logged-in session mode (an account whose credential holds a browser session's user id,
  cookies and x-bc): tested with fakes only, no real logged-in session was used.
- Real Fans API error responses (429/5xx shape, whether `Retry-After` is sent): every live call
  returned 200.
- Setup on macOS or Linux; only Windows 11 + Docker Desktop was used.
- Behaviour at production scale; the throughput check is one healthy account on one machine.
- Two workers truly racing on the same profile at the same instant: covered by the database
  statement (one conditional `UPDATE`) and the per-profile lock, tested only sequentially.
- Crash cases beyond a killed worker process (Redis or database failures, partitions).
