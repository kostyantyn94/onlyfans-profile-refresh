# How AI was used

## Tools

- **Claude Code** (Anthropic, model Claude Opus 5.5) as an agent in VS Code, with shell, Docker
  and browser access. It read the task and the Fans API docs, proposed the plan and the data
  source, wrote the code, tests, workload and these documents, ran everything and recorded the
  evidence. Commits carry a `Co-Authored-By` line for that reason.
- Further Claude Code agents did **independent reviews** of the finished code, with no access to
  how it was written (prompt: find correctness bugs, unsupported claims, missing requirements,
  vacuous tests). There were two for the first submission and three rounds after your review. The
  fourth round had three reviewers in parallel (the refresh path, the signing code, and the whole
  submission read as a hiring reviewer would), the fifth two, of the code and documents after it.
  Their findings and what was done with them are below.
- A further agent redesigned the UI in a separate git worktree (Blade and plain CSS, no build step);
  I merged and checked it.
- My part: setting the constraints (time box, English-only deliverables, no keys in git, ask
  before system changes), approving the plan and the data source, giving access to my Fans API
  account (the agent created a read-only key in my logged-in browser session and saved it from the
  clipboard straight into a local file outside the repo), running every signed request to
  onlyfans.com, and reviewing the results.
- No other AI tools. Documentation used: docs.onlyfansapi.com (a local copy from the previous
  stage) and the Laravel/Horizon/Scout source in `vendor/` when behaviour was unclear.

## What was checked, and how

- **Live data from onlyfans.com**: signed guest requests for madison420ivy through the full job
  path, in the CLI and through Horizon, signed with the rules read from OnlyFans' web app (its
  6 Nov release). Both returned HTTP 200 and 612,132 likes on 7 Nov, and the stored row was checked
  against the 85-field response ([14](docs/evidence/14-live-onlyfans-fetch.txt)). The signature is
  also checked against fixed values computed separately in Python, and the fake upstream verifies it
  with its own implementation on every workload request.
- **The signing rules read from OnlyFans' own code**: the extractor's output for the live release
  ([16](docs/evidence/16-webapp-rules.txt)), accepted by OnlyFans in the fetch above. The previous
  release's rules were accepted on 4 Nov in a separate probe
  ([15](docs/evidence/15-live-probes.txt), section 3). The synthetic code the extractor tests use is a
  working program: it runs in Node and signs exactly as its rules say ([docs/signing.md](docs/signing.md)).
- **The failure before the fix**: the incident tests were committed with only the old handler in
  the tree (commit `83496a9`, [01](docs/evidence/01-incident-tests-before-fix.txt)) and fail 6 of 7
  there; at the current commit they pass with the fix and still fail 6 of 7 with
  `REFRESH_HANDLER=legacy` ([01b](docs/evidence/01b-incident-tests-legacy-now.txt)).
- **Real worker path**: besides calling `handle()` directly, [`RealWorkerTest`](tests/Feature/RealWorkerTest.php)
  pushes jobs through Redis and runs `queue:work --once`, so the gate, the per-profile lock,
  `release()`, `$backoff` and Redis's delayed set are the real ones.
- **Workload numbers** come from two independent counters: the fake upstream counts every request
  by status, the app records every attempt. Stored data is checked row by row, not inferred from
  "job completed".
- **Worker crash**: a real `SIGKILL` of a Horizon worker, after and before the database write,
  waiting for the real `retry_after` redelivery.
- **Secrets**: a test and every workload run search the logs for the API keys and every session
  token the fake upstream saw (0 found); a local pre-commit hook (not part of the repository)
  refuses `.env` and anything that looks like an `ofapi_` key. The
  submission ZIP is built from a fresh clone, so the local `.env` with the key is not in it.
- **Clean setup**: a fresh clone in an empty folder at the final code, `setup.sh`, then the test
  suite, the rules read from OnlyFans' web app and the UI: [10](docs/evidence/10-clean-setup.txt).

## Incorrect suggestions and mistakes caught

**First submission**, from my own runs: a shell edit that stripped every quote from
`config/horizon.php` (caught by `php -l`) and another that emptied `RefreshScheduler.php` (caught by
file sizes; scripted edits now refuse to write unless their anchor occurs exactly once); a PHP image
build stalled on a slow Alpine mirror; `busybox cp -an` silently copying nothing; a Laravel 13
pagination view that does not exist; `horizon:pause` from the wrong container doing nothing;
re-fetching not repairing the zeros the old handler stored at the real revision (now
`profiles:repair`); `setup.sh` failing under Git Bash (found by the clean-clone run).

From the first two independent reviews (all confirmed, fixed unless noted):
- `retryUntil()` fixed at dispatch would have failed every refresh in a backlog, the incident's own
  symptom; removing it exposed that a plain `queue:work` defaults to one try.
- The last 429 of a refresh did not pause the account, and the cooldown could be shortened under a
  race (now one atomic Lua script).
- Final failures retried hourly forever, and the failure backoff counted attempts instead of refreshes.
- The scheduler's default cap was below the 579/s target.
- The claim expired after 30 minutes and was never renewed, so a backlog queued duplicates.
- A 12 h old Fans API cache hit counted as a fresh refresh.
- 5xx retries lasted only seconds, and crash loops had no limit.
- Several README claims had no evidence; they now have it or were reworded.
- Not changed, and listed as limits: the gate re-queues throttled work into the shared queue, and
  there is no account-level circuit breaker.

## Second round, after your review (4 Nov)

Same tool, same way of working: Claude Code wrote the OnlyFans client, the fixes, the tests and
these notes; I set the direction and ran the live checks.

- **Every signed request to onlyfans.com was sent by me.** The agent wrote small probe scripts
  (which rule feed is current, which headers and cookies a guest request needs, what a bad signature
  and an unknown user get, whether the rules read from OnlyFans' code are accepted) and the live
  check through the job. I ran them and pasted the output back, and the client was built from that
  ([15](docs/evidence/15-live-probes.txt), [14](docs/evidence/14-live-onlyfans-fetch.txt),
  [17](docs/evidence/17-previous-release-rules.txt)). The
  agent itself only downloaded OnlyFans' public page and scripts, as a browser does, to read the
  rules.
- **Each of your three findings has a regression test that fails on the reviewed code**, recorded
  with the fix stashed: [12](docs/evidence/12-review-fixes-tests.txt).
- **After the submission was otherwise ready, I asked for the signing rules to come from OnlyFans
  itself** rather than from third-party feeds, since reading them is the core of this kind of work.
  The agent read the obfuscated signing module offline, as text, and wrote
  [`WebAppRulesExtractor`](app/Upstream/OnlyFans/WebAppRulesExtractor.php) and a small expression
  evaluator; neither executes the downloaded code. Its result for the real release was checked on
  the downloaded files (not recorded: OnlyFans' code is not in the repository) and live
  ([16](docs/evidence/16-webapp-rules.txt)). The tests use synthetic code of the same shape,
  written from scratch ([`SyntheticWebApp`](tests/Support/SyntheticWebApp.php)).

Mistakes caught in this round:

- **Two existing tests only passed because of your first finding.** They fed a Fans API snapshot
  cached at 08:12 to a refresh running hours later; the refresh asked again with `?fresh=true`, got
  the same old snapshot and stored it as a success. They now run shortly after the snapshot.
- **Guzzle's `SetCookie::validate()` rejects a cookie without a `Domain`**, and OnlyFans sets its
  `sess` cookie without one: the session would have silently lost its cookie.
- **The rules format `"prefix:{}:{:x}:suffix"` has a colon inside `{:x}`.** Splitting it on every
  colon crashed the first probe script and gave the fake upstream the wrong suffix; the latter was
  caught by a workload in which every signature was rejected.
- **The workload report undercounted "reported successful"** (whole seconds compared with a start
  time in microseconds).
- **The extractor's first version** referred to the wrong capture group for the `sign` assembly and
  did not expect the checksum's `.toString(16)`; both found on the real code before any test existed.
  A test for a subtracted digest character first subtracted an index the rules already add twice (a
  net weight of 0): it failed, which showed it tested nothing.
- `pint --test` over the whole project found inline class names in seven older files.

**Third independent review** (of the OnlyFans client), all confirmed and fixed, each with a test:
- one distrusted session could switch off the current rules for every account (now counted per
  session);
- rules loading could hold workers past the job timeout;
- concurrent responses could overwrite the session or bring back a dropped one;
- removing a login credential was not honoured;
- the stale check trusted `is_cached`.

**Fourth independent review** (three reviewers, after the rules extraction), all confirmed in the
code and fixed with a test unless noted:

- **The 10-attempt limit did not survive a crash.** An attempt was recorded only with its result,
  so a call whose worker died was not counted and the redelivery repeated it. Attempts are now
  recorded before the request (`in_flight`) and completed in the transaction with the profile write.
- **Good rules could be switched off by two distrusted sessions hours apart.** The rejection window
  slid with every rejection, and successes did not count. It is now a fixed window, and rules
  OnlyFans accepted in the last 10 minutes are not counted against. A response from a newer release
  (`x-of-rev`) reloads the rules.
- **A passing 503 on `app.js` counted as unreadable code** and kept the web app out for 10 minutes,
  and one failing script stopped the search for the signing module.
- **The extractor could return part of a helper value.** It would read `o(1,"x").split("")…` as just
  `o(1,"x")`: plausible, wrong rules, cached for the release. Such entries are now refused. The module
  is cut out by its exact id, escapes are read in one pass, UTF-16 surrogates are kept, and sizes are
  capped.
- **The synthetic test code was not a working program** (its decoder did not decode), so the
  extractor was only checked against a generator written alongside it. The decoder is real now, and
  the code runs in Node and signs as its rules say.
- **Cookies lost their expiry** (`__cf_bm` was sent for 24 h instead of 30 min). A late rejection of
  an old session could drop its replacement, and an unreadable stored session was never replaced.
- **Smaller fixes:**
  - a job waiting for another worker's rules load spent an attempt;
  - exceptions had no backoff, so a short database outage used up `$maxExceptions` in milliseconds;
  - an old job giving up could overwrite a newer refresh's status;
  - `Retry-After` was ignored on 503;
  - a Fans API answer of unknown age passed as fresh.
- **Defaults:** third-party rule feeds were in the default path, and they are now opt-in. The UI
  port was published on all interfaces, so the read-only demo could be bypassed from the network; it
  is now 127.0.0.1 only. The README was reordered to lead with the data retrieval, and the evidence
  re-recorded, each file stamped with its commit: the workloads at 3665d45 (later commits changed
  only how rules are read from OnlyFans' web app, which the local workloads do not use), the test
  suite, the rules and the live check at the final code.
**Fifth independent review** (two reviewers, of the code and documents after the fourth), all
confirmed and fixed with a test:

- **Skipping the only rules source stopped everything.** With only the web app configured, two
  rejected sessions skipped its rules, and nothing could clear the count again because no request
  was sent. The last source is now never skipped, and rejections are counted per release.
- **A new release reloaded the rules on every response** that showed it (a CDN serving the old page
  for a while). It now reloads once per release.
- **The "last good rules" expired after a day** in the cache, despite what the docs said. They are
  now kept until replaced.
- **An answer was never checked against the profile asked for.** Another username, or another
  upstream id than the one stored, is now `invalid_response`, and nothing is written.
- **A failure while building the request** (e.g. Redis down) left a phantom `in_flight` attempt.
  The attempt is now recorded by the client right before the request goes out.
- **The workload's secrets check** looked only at the session alive at the end. It now checks every
  `x-bc` and cookie the fake upstream saw.
- Also: drift in the docs, a clean-setup check from the first version (re-run), and the scaling
  arithmetic using the fake upstream's latency instead of the live one.
- **Not changed:**
  - the account gate still re-queues throttled work into the shared queue (a README limit);
  - the `accounts.api_key` column keeps its name, although it now also holds an OnlyFans session
    credential.

**A last readiness check (6 Nov)** found that OnlyFans had shipped a new release that day, and
the extractor refused it: the obfuscator had changed the order of two statements, the order of a
wrapper's arguments, and what follows the helper object. Nothing wrong was signed; refreshes would
have kept the last good rules. A fresh setup, though, has no last good rules, so `profiles:fetch`
would have failed. The extractor was adjusted to read both shapes, the synthetic web app learned the
new one, and the live check was repeated on the new release on 7 Nov: HTTP 200 in the CLI and in
Horizon ([14](docs/evidence/14-live-onlyfans-fetch.txt),
[docs/signing.md](docs/signing.md#where-the-rules-come-from-onlyfans-own-web-app)). So that a fresh
setup does not fail the next time, the rules OnlyFans last accepted are now kept in the repository
as the last default source, used with a warning when the web app's code cannot be read.

**The Fans API client removed (8 Nov).** Since 4 Nov it had been kept as a second driver "for
comparison", and its account still showed in the demo. I had it removed: the client, its cache rule
and `?fresh=true` follow-up, its settings, its fixture and the 12 tests that covered them. A
migration deletes `fansapi` accounts from existing databases. Requests go to onlyfans.com only.

## Not verified

- That `favoritedCount` is the "likes" number OnlyFans shows on the profile page: it is the field
  OnlyFans' own api2 returns, but the page itself was not compared.
- How long a guest session stays valid, and how many requests OnlyFans allows per session or per
  IP before it throttles: only a handful of live requests were made. 429 and 5xx from OnlyFans were
  never seen live; their handling is tested against fakes.
- That the rules extraction keeps working across OnlyFans releases. It reads the releases of 3 and
  6 Nov, and the second one already needed three small adjustments. The next change of shape will
  make it fail loudly again; the last good rules (on a fresh setup, those kept in the repository)
  stay in use until it is adjusted, for as long as OnlyFans accepts them.
- How long OnlyFans keeps accepting an earlier release's rules. On 4 Nov it accepted the rafa-9
  feed's, which differ from the current release's, and rejected two other feeds'. On 7 Nov, a day
  after the 6 Nov release, it still accepted the 3 Nov rules
  ([17](docs/evidence/17-previous-release-rules.txt)).
- The logged-in session mode (an account whose credential holds a browser session's user id,
  cookies and x-bc): tested with fakes only, no real logged-in session was used.
- Setup on macOS or Linux; only Windows 11 + Docker Desktop was used.
- Behaviour at production scale; the throughput check is one healthy account on one machine.
- Two workers truly racing on the same profile at the same instant: covered by the database
  statement (one conditional `UPDATE`) and the per-profile lock, tested only sequentially.
- Crash cases beyond a killed worker process (Redis or database failures, partitions).
