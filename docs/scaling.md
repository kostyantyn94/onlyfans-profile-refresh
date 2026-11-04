# The next scaling limit: 50 million jobs a day

50 M jobs/day is 579 jobs/s on average. That says nothing about the peak or the capacity needed;
this is what I measured locally, what I would measure next, where I expect this solution to break
first, and what I would change first.

## What one machine did

`php artisan workload:throughput --profiles=3000` ([07](evidence/07-throughput.txt)): one healthy
account, 50 ms of upstream latency, 4 Horizon workers.

| | |
|---|---|
| Throughput | **49 refreshes/s, ~12 per worker**, through the signed OnlyFans client (the first version, with the Fans API client, did 55/s) |
| Time per refresh | ~82 ms: upstream call p50 57 ms (p95 112 ms), the rest our own work |
| Our work per refresh | 8 SQL statements (5 reads, 3 writes; the write and its attempt row in one transaction) and a few Redis calls (gate, lock, Horizon, the cached signing rules and session) |
| Worker memory | highest peak seen: 36.5 MB |

`php artisan workload:scheduler-memory --profiles=30000` ([09](evidence/09-scheduler-memory.txt)):
`profiles:enqueue-due` in its own process claims and dispatches **430–580 refreshes/s** in this run
(650–700 in the first version's run; the machine was busier this time, and the throughput run above
claimed its 3,000 at 896/s) and peaks at
**30 MB** whether it queues 3,000 or 30,000, because it claims in batches of 500 and never builds
models.

A few thousand local jobs are not evidence that this handles 50 M/day; they give a per-worker
rate to size from and show where to look first.

## What I would measure next

| Question | Signal |
|---|---|
| Peak arrivals | refreshes queued per second, in total and per account; how much the 24 h / 72 h schedule bunches up (profiles added together come due together) |
| Job duration | p50/p95/p99 of the upstream call and of our own time, per account |
| Concurrent work | in-flight jobs = arrival rate × duration (Little's law): 579/s × 82 ms ≈ 47 busy workers on average; several times that at peak or when the upstream slows to seconds |
| Retry amplification | upstream requests per successful refresh, by outcome: 429s, 5xx, `signature_rejected` after each OnlyFans release (and, with the Fans API driver, the `?fresh=true` follow-ups) |
| Database | statements/s (≈ 8 × 579 ≈ 4.6 k/s on average, 1.7 k of them writes), WAL volume, lock waits on `profiles`, growth of `refresh_attempts` (≥ 50 M rows/day) |
| Redis | ops/s, memory used by Horizon's job metadata, size of the delayed set, how many pops end in a deferral |
| Upstream limits | OnlyFans publishes none: 429s and `signature_rejected` per session (account) and per egress IP, and how many requests a guest session makes before OnlyFans throttles or drops it. For the Fans API driver: `_meta._rate_limits.remaining_minute`; our key allows **60 requests/min**, the docs list 60, 1,000 and 5,000 per minute by plan (1, 17 and 83 requests/s per team) |
| Signing | rule loads per host per hour (one loader at a time, so this does not grow with traffic), how long feeds lag behind an OnlyFans release, and how many requests fail with `signature_rejected` per rotation |
| Largest accounts | due profiles per account: one account with millions of profiles and a 2-slot cap cannot keep up |

## Likely bottleneck in this solution

**The scheduler.** One process queues 430–900/s: about the 579/s average and below any peak. Every
dispatch is one Redis round trip plus Horizon's bookkeeping plus a log line.

Close behind it:

- **The account gate's re-queueing.** A throttled account's jobs go back into the one shared queue
  every 1–3 s, or all at once when a cooldown ends. With 40 jobs that is invisible; with tens of
  thousands queued for one account, workers would spend most of their time popping and deferring
  them, and every other account would wait behind that churn: the starvation the gate exists to
  prevent.
- **Postgres writes:** three per refresh, and a `refresh_attempts` table that grows by tens of
  millions of rows a day.

Outside our control, the hard ceiling is OnlyFans' own throttling per session and per IP, which is
not published and which I have not measured (a handful of live requests). Whatever it is, 579/s
means many sessions, and per-account pacing matters more than the number of workers. Signing itself
costs one SHA-1 per request and the rules are loaded once per host per hour plus after rejections,
so it does not grow with traffic; a rules rotation costs every request in flight one
`signature_rejected` retry until the new rules are loaded. (With the Fans API driver the ceiling
was the per-team limit: 1 refresh/s on our 60-requests/min key, 83/s at 5,000/min.)

**Evidence to collect:** scheduler lag (age of the oldest due but unclaimed profile) and loop
time; Horizon wait time on `redis:refresh` (Horizon raises `LongWaitDetected` at 60 s; it needs a
notification channel to reach anyone); deferrals per minute per account; `pg_stat_statements` top
statements by total time; Redis `INFO commandstats`; 429s per account.

## First change

Make queueing cheap and parallel:

1. Dispatch each claimed batch of 500 in one Redis pipeline instead of one round trip per job,
   and log one line per batch instead of one per job.
2. Run several scheduler shards. The claim (`FOR UPDATE SKIP LOCKED`) is built for that; I saw it
   split rows between two runs once by accident (the scheduler container ran during a measurement),
   but there is no test of it.
3. Add jitter to `next_refresh_at` so the 24 h / 72 h schedule does not come due in waves.

Then, in order:

- Stop re-queueing throttled work. Either give each large account its own queue, or let the
  scheduler dispatch only as many refreshes per account as its limit allows, and keep the rest
  waiting in the database rather than in Redis.
- Pace each account with a token bucket sized to its upstream limit (learned from 429s per session
  for OnlyFans; `_meta._rate_limits.remaining_minute` for Fans API) instead of discovering the limit
  through 429s every time, with an account-level circuit breaker for runs of 5xx and for 401/403.
- Derive the signing rules from OnlyFans' own JavaScript bundle as one more source, so a release is
  followed within minutes and not when a public feed catches up.
- Partition `refresh_attempts` by day with a short retention (or keep only failures).
