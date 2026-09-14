# Changelog

All notable changes to `laravel-race-guard` will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added

- **Rule codes and categories.** Every finding now carries a stable `RG` code
  (RG001–RG013) and a category (payments, inventory, database, transactions,
  locks, idempotency, queue, scheduler, cache, external). The console report is
  grouped by category and tags each finding with its code; JSON output includes
  both. See `RULES.md` for the full catalogue.
- `race:check --category=…` to report only selected categories (repeatable).
- Rule **RG005 unsafe_state_transition** — a status/state column checked, then
  set with an unconditional `update()`; suggests a first-wins conditional update.
- Rule **RG006 missing_idempotency** — a queued job performing an external side
  effect (HTTP/charge/mail) with no idempotency key.
- Rule **RG008 external_side_effect_before_commit** — HTTP/mail/notification/
  dispatch/event inside `DB::transaction()`, before commit.
- Rule **RG010 missing_database_uniqueness** — advisory (LOW) that
  `firstOrCreate()`/`updateOrCreate()` need a DB unique index to be safe.
- Initial detection engine: Git-aware file discovery, `nikic/php-parser` AST
  pipeline, severity levels, console and JSON reporters, and the `race:check`
  Artisan command.
- Rule: **read_modify_write** — read a model attribute, change it by a variable
  amount, save it back without a lock.
- Rule: **non_atomic_counter** — `$model->count = $model->count + 1; save()` and
  the `+=` / `++` variants.
- Rule: **check_then_act** — check a fetched model's state, then mutate and save
  it inside the same `if`.
- Rule: **check_then_create** — existence check (`exists()`, `count()`,
  null `first()`) gating a non-atomic `create()`.
- Rule: **unsafe_balance_update** — a sufficiency/threshold check on a model
  attribute followed by an unlocked `decrement()`/`increment()` of it.
- Rule: **guard_then_save** — the manual-reassignment sibling of
  `unsafe_balance_update` (check, then `$m->attr = …; $m->save()`).
- Rule: **transaction_without_lock** — a read-modify-write inside
  `DB::transaction()` with no `lockForUpdate()`.
- Rule: **non_atomic_cache** — `Cache::get()` then arithmetic `Cache::put()`
  instead of `Cache::increment()` / `Cache::lock()`.
- Rule: **overlapping_job** — a queued job that mutates state in `handle()`
  without `ShouldBeUnique` or the `WithoutOverlapping` middleware.
- Safe-pattern awareness: `lockForUpdate()`, `sharedLock()`, `Cache::lock()`,
  atomic `increment()`/`decrement()`, `firstOrCreate()`/`updateOrCreate()`/
  `insertOrIgnore()`, and atomic conditional updates.
- Configurable rules, minimum severity, `fail_on` threshold, and diff
  granularity (`--whole-file` / `--changed-lines`).
