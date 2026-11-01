# How AI was used

## Tools

- **Claude Code** (Anthropic, model Claude Opus 5.5) as an agent in VS Code, with shell, Docker
  and browser access. It read the task and the Fans API docs, proposed the plan and the data
  source, wrote the code, tests, workload and these documents, ran everything and recorded the
  evidence. Commits carry a `Co-Authored-By` line for that reason.
- A second Claude Code agent did an **independent review** of the finished code with no access to
  how it was written (prompt: find correctness bugs, unsupported claims, missing requirements,
  vacuous tests). Its findings and what was done with them are below.
- My part: setting the constraints (time box, English-only deliverables, no keys in git, ask
  before system changes), approving the plan and the data source, giving access to my Fans API
  account (the agent created a read-only key in my logged-in browser session and saved it from the
  clipboard straight into a local file outside the repo), and reviewing the results.
- No other AI tools. Documentation used: docs.onlyfansapi.com (a local copy from the previous
  stage) and the Laravel/Horizon/Scout source in `vendor/` when behaviour was unclear.

## What was checked, and how

- **Live data**: a real `GET /api/profiles/madison420ivy` through the full job path, then through
  Horizon; the stored row compared with the raw response (likes 611,905, 87 fields).
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
- **Clean setup**: a fresh clone in an empty folder, `setup.sh`, then the test suite, a keyless
  `profiles:fetch` (recorded as `auth_failed`, HTTP 401) and the workload. Setup took 33 s.

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
- **Re-fetching does not repair rows the old handler broke**: it stored likes 0 with the real
  revision 11, which the revision guard then treats as "unchanged". Added `profiles:repair` and a
  test that shows the plain re-fetch keeps the zero.
- **The scheduler container could claim workload profiles first**, mixing handlers in the "before"
  run; workload profiles are now created as not due, and the evidence script stops the scheduler.
- **`profiles:fetch` in sync mode left a profile claimed after a 429**; the retry now goes to Horizon.
- **`setup.sh` failed under Git Bash on Windows** (`/app/vendor` rewritten to a Windows path), found
  by the clean-clone run.

From the independent review (all confirmed by reading the code or a test, then fixed):

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

## Not verified

- That `favoritedCount` is the "likes" number OnlyFans shows on the profile: taken from the field
  name and Fans API usage. The browser tool refused to open onlyfans.com.
- Real Fans API error responses (429/5xx shape, whether `Retry-After` is sent) and `?fresh=true`:
  every live call was a cache hit.
- Setup on macOS or Linux; only Windows 11 + Docker Desktop was used.
- Behaviour at production scale; the throughput check is one healthy account on one machine.
- Two workers truly racing on the same profile at the same instant: covered by the database
  statement (one conditional `UPDATE`) and the per-profile lock, tested only sequentially.
- Crash cases beyond a killed worker process (Redis or database failures, partitions).
