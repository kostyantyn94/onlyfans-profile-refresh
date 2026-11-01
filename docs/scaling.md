# The next scaling limit: 50 million jobs a day

50 M jobs/day is 579 jobs/s on average. That says nothing about the peak or the capacity needed;
this is what I measured locally, what I would measure next, where I expect this solution to break
first, and what I would change first.

## What one machine did

`php artisan workload:throughput --profiles=3000` ([07](evidence/07-throughput.txt)): one healthy
account, 50 ms of upstream latency, 4 Horizon workers.

| | |
|---|---|
| Throughput | **55 refreshes/s, ~14 per worker** |
| Time per refresh | ~73 ms: ~54 ms upstream call, ~19 ms our own work |
| Our work per refresh | 7 SQL statements (4 reads, 3 writes) and a few Redis calls (gate, lock, Horizon) |
| Worker memory | 36.5 MB peak, flat |

`php artisan workload:scheduler-memory --profiles=30000` ([09](evidence/09-scheduler-memory.txt)):
`profiles:enqueue-due` in its own process claims and dispatches **~700 refreshes/s** and peaks at
**30 MB** whether it queues 3,000 or 30,000, because it claims in batches of 500 and never builds
models.

A few thousand local jobs are not evidence that this handles 50 M/day; they give a per-worker
rate to size from and show where to look first.

## What I would measure next

| Question | Signal |
|---|---|
| Peak arrivals | refreshes queued per second, in total and per account; how much the 24 h / 72 h schedule bunches up (profiles added together come due together) |
| Job duration | p50/p95/p99 of the upstream call and of our own time, per account |
| Concurrent work | in-flight jobs = arrival rate × duration (Little's law): 579/s × 73 ms ≈ 42 busy workers on average; several times that at peak or when the upstream slows to seconds |
| Retry amplification | upstream requests per successful refresh, by outcome (1.35 in the busy scenario) |
| Database | statements/s (≈ 7 × 579 ≈ 4 k/s on average, 1.7 k of them writes), WAL volume, lock waits on `profiles`, growth of `refresh_attempts` (≥ 50 M rows/day) |
| Redis | ops/s, memory used by Horizon's job metadata, size of the delayed set |
| Upstream limits | 429s per account and per Fans API team; the plans allow 1,000–5,000 requests/min per team, i.e. 17–83/s |
| Largest accounts | due profiles per account: one account with millions of profiles and a 2-slot cap cannot keep up |

## Likely bottleneck in this solution

**The scheduler.** One process queues ~700/s: about the 579/s average and below any peak. Every
dispatch is one Redis round trip plus Horizon's bookkeeping plus a log line. Close behind it:
three writes per refresh to Postgres and a `refresh_attempts` table that grows by tens of millions
of rows a day. Outside our control, the hard ceiling is the upstream's per-team rate limit: at
5,000 requests/min a single team cannot do more than 83 refreshes/s, so 579/s needs many teams or
an enterprise limit, and per-account pacing matters more than worker count.

**Evidence to collect:** scheduler lag (age of the oldest due but unclaimed profile) and loop
time; Horizon wait time on `redis:refresh` (an alert is configured at 60 s); `pg_stat_statements`
top statements by total time; Redis `INFO commandstats`; 429s per account.

## First change

Make queueing cheap and parallel:

1. Dispatch each claimed batch of 500 in one Redis pipeline instead of one round trip per job,
   and log one line per batch instead of one per job.
2. Run several scheduler shards. The claim (`FOR UPDATE SKIP LOCKED`) already lets them split the
   work; in a test a second scheduler took a share of the rows as designed.
3. Add jitter to `next_refresh_at` so the 24 h / 72 h schedule does not come due in waves.

Then: partition `refresh_attempts` by day with a short retention (or keep only failures), and pace
each account with a token bucket sized to its upstream limit, reading
`_meta._rate_limits.remaining_minute` from Fans API responses, instead of discovering the limit
through 429s.
