# How AI was used

## Tools

- **Claude Code** (Anthropic, model Claude Opus 5.5) as an agent in VS Code, with shell, Docker
  and browser access. It read the task and the Fans API docs, proposed the plan and the data
  source, wrote the code, tests, workload and these documents, ran everything and recorded the
  evidence. Commits carry a `Co-Authored-By` line for that reason.
- My part: setting the constraints (time box, English-only deliverables, no keys in git, ask
  before system changes), approving the plan and the data source, giving access to my Fans API
  account (the agent created a read-only key in my logged-in browser session and saved it straight
  from the clipboard into a local file outside the repo), and reviewing the results.
- No other AI tools. Documentation used: docs.onlyfansapi.com (a local copy from the previous
  stage), Laravel/Horizon/Scout source in `vendor/` when behaviour was unclear.

## What was checked, and how

- **Live data**: one real `GET /api/profiles/madison420ivy` through the full job path, then via
  Horizon; the stored row compared with the raw response (likes 611,905, 87 fields).
- **The failure before the fix**: the incident tests were committed with only the old handler
  in the tree (commit `83496a9`) and fail 6 of 7 there; the same tests pass with the fix and still
  fail with `REFRESH_HANDLER=legacy`.
- **Tests are not vacuous**: the account-isolation test asserts the busy account made no upstream
  call (`Http::assertSentCount(1)`); the late-response test uses a real sequence of two answers;
  retry budgets are checked by counting recorded attempts, not by trusting the job.
- **Workload numbers** come from two independent counters that agree: the fake upstream counts
  every request by status, and the app records every attempt (55 and 55 in the fixed run). Stored
  data is checked row by row, not inferred from "job completed".
- **Worker crash**: a real `SIGKILL` of a Horizon worker process, both after and before the
  database write, waiting for the real `retry_after` redelivery.
- **Secrets**: a test plus every workload run search the logs for the API keys (0 found); the
  pre-commit hook refuses `.env` and anything that looks like an `ofapi_` key.
- **Clean setup**: cloned the repository into an empty folder and ran `setup.sh` and the test
  suite there (see README).

## Incorrect suggestions and mistakes caught

- **A shell edit stripped every quote from `config/horizon.php`**, leaving invalid PHP. Caught by
  `php -l` before anything ran; the setup commit was redone with the fixed file.
- **Another scripted edit emptied `RefreshScheduler.php`** (a failed regex returned `null`, which
  was written back). Caught by checking file sizes; restored from a copy made just before.
  After that, multi-line edits went through small PHP scripts that refuse to write when the anchor
  is not found exactly once.
- **Building a PHP image from `php:8.4-cli-alpine`** was the first plan. The Alpine mirror delivered
  ~250 KB/s and the build was still installing a compiler after 15 minutes; replaced with a
  prebuilt image that already has `pcntl`, `redis` and `pdo_pgsql`.
- **`busybox cp -an` silently copied nothing** into the project folder (no-clobber on the existing
  target directory). Noticed because `composer.lock` was missing.
- **`pagination::simple-default`** does not exist in Laravel 13; the page returned 500. Caught by
  requesting every page with `curl` after writing them.
- **`$job->job->uuid()`** would break with Laravel's `FakeJob` (no payload); switched to
  `getJobId()` after reading the framework source, before running.
- **A loop of `Http::fake()` calls in one test** would have kept answering with the first fake;
  rewritten as a data provider.
- **`horizon:pause` from the `app` container does nothing**: it signals the Horizon master by
  PID, which lives in another container. The first memory benchmark therefore ran with workers
  still active; its memory result stands, its row counts were explained rather than reused.
- **A design gap found while writing the production plan**: re-fetching does not repair rows the
  old handler broke, because it stored likes 0 with the real revision 11 and the revision guard
  then treats revision 11 as "unchanged". Added `profiles:repair` and a test that shows the plain
  re-fetch keeps the zero.
- **The first workload design** let the scheduler container claim workload profiles before the
  workload queued them (they were created as due), which could have mixed handlers in the "before"
  run. Workload profiles are now created as not due.
- **`profiles:fetch` in sync mode** left a profile claimed after a 429, because the sync driver
  cannot delay a retry. Found in review; the retry now goes to Horizon, and the live run is the
  log example in the README.

## Not verified

- That `favoritedCount` is the "likes" number OnlyFans shows on the profile: taken from the field
  name and Fans API usage. The browser tool refused to open onlyfans.com.
- Real Fans API error responses (429/5xx shape, whether `Retry-After` is sent) and `?fresh=true`:
  every live call was a cache hit.
- Setup on macOS or Linux; only Windows 11 + Docker Desktop was used.
- Behaviour at production scale; the throughput check is one healthy account on one machine.
- Crash cases beyond a killed worker process (Redis or database failures, partitions).
