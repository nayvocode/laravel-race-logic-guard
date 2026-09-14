# Laravel RaceGuard — Rule Catalogue

Every finding carries a stable **RG code**, a **category**, and a **severity**,
and a **context-aware suggestion** tailored to the pattern (not a generic
"use a transaction"). Rules are toggled individually in `config/race-guard.php`
and can be filtered at runtime with `--category`.

```bash
php artisan race:check                       # everything, Git-aware
php artisan race:check --category=payments   # one category
php artisan race:check --category=queue --category=cache
php artisan race:check --severity=high       # only high+ findings
php artisan race:check --format=json         # machine-readable (CI)
```

## Categories

`payments` · `inventory` · `database` · `transactions` · `locks` ·
`idempotency` · `queue` · `scheduler` · `cache` · `external`

## Implemented rules

| Code | Rule | Category | Severity | Detects | Recommended fix |
|------|------|----------|----------|---------|-----------------|
| RG001 | Read-modify-write | database | HIGH | `$m->x = $m->x - $y; $m->save();` | atomic `decrement()` / conditional `UPDATE` |
| RG002 | Check-then-act | database | HIGH | check a fetched model's state, then mutate + `save()` in the same `if` | `lockForUpdate()` in a transaction |
| RG003 | Check-then-create | database | HIGH | `exists()`/`count()`/null-`first()` gating a `create()` | unique index + `firstOrCreate()` |
| RG004 | Check-then-decrement | inventory | HIGH | sufficiency check then unlocked `decrement()`/`increment()` (balances, stock, coins, quotas) | conditional atomic `UPDATE` (`where('x','>=',$n)->decrement(...)`) |
| RG005 | Unsafe state transition | database | HIGH | status/state checked, then an unconditional `update(['status' => …])` | first-wins `where('status',$from)->update(...)`, check affected rows |
| RG006 | Missing idempotency | idempotency | MEDIUM | queued job doing an external effect (HTTP/charge/mail) with no idempotency key | stable idempotency key / processed-event unique row |
| RG007 | Transaction without lock | transactions | HIGH | read-modify-write inside `DB::transaction()` with no `lockForUpdate()` | lock the row inside the transaction |
| RG008 | External side effect before commit | external | MEDIUM | HTTP/mail/notification/dispatch/event inside a transaction, before commit | `afterCommit()` / `dispatch()->afterCommit()` |
| RG009 | Duplicate job processing | queue | MEDIUM | queued job mutates state with no `ShouldBeUnique`/`WithoutOverlapping` | `ShouldBeUnique` or `WithoutOverlapping` middleware |
| RG010 | Missing database uniqueness | idempotency | LOW | `firstOrCreate()`/`updateOrCreate()` (safe only with a DB unique index) | add unique index, handle the conflict |
| RG011 | Non-atomic counter | database | MEDIUM | `$m->count = $m->count + 1; save()` (views, likes, attempts) | `increment()` / `decrement()` |
| RG012 | Guard-then-save | payments | HIGH | threshold check then a manual reassignment + `save()` | lock the row, or an atomic conditional update |
| RG013 | Non-atomic cache | cache | MEDIUM | `Cache::get()` → arithmetic → `Cache::put()` | `Cache::increment()` / `Cache::lock()` |

### Safe patterns RaceGuard recognises (and stays quiet on)

`lockForUpdate()`, `sharedLock()`, `Cache::lock()`, atomic
`increment()`/`decrement()`, `firstOrCreate()`/`updateOrCreate()`/
`insertOrIgnore()`, conditional atomic updates, `ShouldBeUnique`,
`WithoutOverlapping`, and `dispatch()->afterCommit()`.

## Roadmap

These patterns are on the list to add. Feasibility reflects how reliably static
analysis can flag them without a high false-positive rate.

**Queue & scheduler** — Scheduler overlap without `withoutOverlapping()`
(feasible); queue-retry idempotency for external effects (partly covered by
RG006/RG008); exactly-once assumptions on at-least-once queues (advisory).

**Locks** — Lock-after-read (record read before the lock is acquired); wrong-row
lock (lock on a different row than the one written); critical write outside the
locked transaction; broad/unstable cache-lock keys; missing lock release
without `try/finally` (all feasible, medium FP).

**Idempotency & external** — Webhook duplication without event-id dedup;
payment idempotency keys; DB + external-API split-brain / outbox candidates
(feasible when scoped to controllers/jobs).

**Stale writes** — Stale-model writes (loaded early, saved late); lost-update
across fields; optimistic-locking (version column) suggestions (feasible,
advisory).

**Domain races** — Inventory oversell, wallet double-spend, coupon/limit abuse,
quota caps, seat/slot reservation, first-wins claim/redeem (mostly specialised
presentations of RG004/RG005 — will be surfaced with domain-specific messaging).

**Sequences & tokens** — `max(id)+1` / invoice / ticket number generation;
daily-counter identifiers; one-time-token and password-reset consume-after-action
(feasible, medium FP).

**Transactions** — Long-running transactions (HTTP/sleep/mail inside a
transaction), nested-transaction assumptions, after-commit correctness,
event/listener-before-commit timing (feasible, extends RG008).

**Data & files** — Concurrent batch updates that should be set-based; pivot
`attach()`/`sync()` races; `upsert()` opportunities; file-based
`exists()`-then-write races (feasible).

**Not planned as static rules** (need runtime/schema knowledge, too high FP to
be useful): guaranteeing a DB unique constraint exists (RG010 is an advisory,
not a proof), deadlock lock-ordering across unrelated code paths, and true
distributed-lock lifetime correctness.
