# The next scaling limit: 50 million jobs a day

50 M jobs/day is 579 jobs/s on average. That says nothing about the peak or the capacity needed;
this is what I measured locally, what I would measure next, where I expect this solution to break
first, and what I would change first.

## What one machine did

`php artisan workload:throughput --profiles=3000` ([07](evidence/07-throughput.txt)): one healthy
account, 50 ms of upstream latency, 4 Horizon workers.

| | |
|---|---|
| Throughput | **48 refreshes/s, ~12 per worker**, through the signed OnlyFans client (the first version, with the Fans API client, did 55/s) |
| Time per refresh | ~83 ms: upstream call p50 57 ms (p95 110 ms), the rest our own work |
| Our work per refresh | 9 SQL statements (5 reads, 4 writes: the attempt row before the request, then the profile and the attempt's result in one transaction) and a few Redis calls (gate, lock, Horizon, the cached signing rules and session) |
| Worker memory | highest peak seen: 36.5 MB |

`php artisan workload:scheduler-memory --profiles=30000` ([09](evidence/09-scheduler-memory.txt)):
`profiles:enqueue-due` in its own process claims and dispatches **800–950 refreshes/s** in this
run (430–580 in an earlier run on a busier machine) and peaks at **30 MB** whether it queues 3,000
or 30,000, because it claims in batches of 500 and never builds models.

A few thousand local jobs are not evidence that this handles 50 M/day; they give a per-worker
rate to size from and show where to look first.

## What I would measure next

| Question | Signal |
|---|---|
| Peak arrivals | refreshes queued per second, in total and per account; how much the 24 h / 72 h schedule bunches up (profiles added together come due together) |
| Job duration | p50/p95/p99 of the upstream call and of our own time, per account |
| Concurrent work | in-flight jobs = arrival rate × duration (Little's law): 579/s × 83 ms ≈ 48 busy workers on average; several times that at peak or when the upstream slows to seconds |
| Retry amplification | upstream requests per successful refresh, by outcome: 429s, 5xx, `signature_rejected` after each OnlyFans release (and, with the Fans API driver, the `?fresh=true` follow-ups) |
| Database | statements/s (≈ 9 × 579 ≈ 5.2 k/s on average, 2.3 k of them writes), WAL volume, lock waits on `profiles`, growth of `refresh_attempts` (≥ 50 M rows/day) |
| Redis | ops/s, memory used by Horizon's job metadata, size of the delayed set, how many pops end in a deferral |
| Upstream limits | OnlyFans publishes none: 429s and `signature_rejected` per session (account) and per egress IP, and how many requests a guest session makes before OnlyFans throttles or drops it. For the Fans API driver: `_meta._rate_limits.remaining_minute`; our key allows **60 requests/min**, the docs list 60, 1,000 and 5,000 per minute by plan (1, 17 and 83 requests/s per team) |
| Signing | rule loads per host per hour (one loader at a time, so this does not grow with traffic), the source in use (`rules_loaded`: the web app, or a feed and why the web app was passed over), and how many requests fail with `signature_rejected` per rotation |
| Largest accounts | due profiles per account: one account with millions of profiles and a 2-slot cap cannot keep up |

## Likely bottleneck in this solution

**First, outside our code: OnlyFans' capacity per identity.** Every request goes out as some
session from some IP, and OnlyFans limits both; neither limit is published, and a handful of live
requests did not reach them. The arithmetic decides the design: if one guest session sustains *r*
requests/s before 429s or challenges, 579/s needs 579 / *r* sessions in parallel, spread over
enough egress IPs (at 1/s about 600 sessions, at 10/s about 60). This service runs one session per
account from one IP, so OnlyFans' limits, not ours, would stop it first. What exists already: a 429
pauses the whole account, an account's concurrency is capped, guest sessions are created on demand,
and rejections are tracked per session.

**Inside our system: the scheduler.** One process queues 800–950/s (430–580 on a busier
machine): about the 579/s average and below any peak. Every dispatch is one Redis round trip plus
Horizon's bookkeeping plus a log line.

Close behind it:

- **The account gate's re-queueing.** A throttled account's jobs go back into the one shared queue
  every 1–3 s, or all at once when a cooldown ends. With 40 jobs that is invisible; with tens of
  thousands queued for one account, workers would spend most of their time popping and deferring
  them, and every other account would wait behind that churn: the starvation the gate exists to
  prevent.
- **Postgres writes:** four per refresh, and a `refresh_attempts` table that grows by tens of
  millions of rows a day.

Signing does not grow with traffic: one SHA-1 per request, and the rules are loaded once per host
per hour plus after a rejection or a new release (the web app's page each time, its code only when
the release is new). While the sources are down, a failed load is remembered for 30 s and the last
good rules stay in use, so an outage does not multiply fetches either. A rules rotation costs the
requests in flight one `signature_rejected` retry each until the new rules are loaded. (With the
Fans API driver the ceiling was the per-team limit: 1 refresh/s on our 60-requests/min key, 83/s at
5,000/min.)

**Evidence to collect:** scheduler lag (age of the oldest due but unclaimed profile) and loop
time; Horizon wait time on `redis:refresh` (Horizon raises `LongWaitDetected` at 60 s; it needs a
notification channel to reach anyone); deferrals per minute per account; `pg_stat_statements` top
statements by total time; Redis `INFO commandstats`; 429s per account.

## First change

1. **Measure one identity, then pool them.** Find the sustained requests/s per guest session and
   per egress IP until the first 429 or challenge. Then give each account a pool of sessions (and
   IPs) sized from that, each paced by a token bucket, and let the account gate pick a free session
   instead of a fixed concurrency of 2.
2. **Make queueing cheap and parallel.** Dispatch each claimed batch of 500 in one Redis pipeline
   instead of one round trip per job, and log one line per batch instead of one per job. Run several
   scheduler shards: the claim (`FOR UPDATE SKIP LOCKED`) is built for that; I saw it split rows
   between two runs once by accident (the scheduler container ran during a measurement), but there
   is no test of it. Add jitter to `next_refresh_at` so the 24 h / 72 h schedule does not come due in
   waves.

Then, in order:

- Stop re-queueing throttled work. Either give each large account its own queue, or let the
  scheduler dispatch only as many refreshes per account as its sessions allow, and keep the rest
  waiting in the database rather than in Redis.
- An account-level circuit breaker for runs of 5xx and for 401/403.
- Load a new release's rules outside refresh jobs: responses already name the release (`x-of-rev`)
  and mark the rules for reloading; a scheduled command could do that load, so no job ever waits for
  it.
- Partition `refresh_attempts` by day with a short retention (or keep only failures).
