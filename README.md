# Laravel RaceGuard

Laravel RaceGuard is a static analysis tool for detecting potential race conditions in Laravel applications.

It focuses on changed Git files, allowing developers to catch concurrency issues before they are committed or deployed.

```bash
php artisan race:check
```

> **Status:** Laravel RaceGuard is under active development. It currently ships **13 rules (RG001–RG013)** across ten concurrency categories. APIs, rules, and configuration may still change before the first stable release.

## Why Laravel RaceGuard?

Race conditions can occur when multiple requests, queue workers, scheduled tasks, or processes access and modify the same data concurrently.

These problems can be difficult to reproduce and may only appear under production load.

For example:

```php
$user = User::find($userId);

$user->balance = $user->balance - 100;

$user->save();
```

If two requests execute this code simultaneously, both requests may read the same balance before either update is written.

Laravel RaceGuard aims to detect patterns like this automatically.

## Rules

Every finding carries a stable **RG code**, a **category**, a **severity**, and a **context-aware suggestion** tailored to the pattern (not a generic "use a transaction"). The full catalogue — with before/after examples and the recommended fix for each — lives in [`RULES.md`](RULES.md).

| Code | Rule | Category | Severity |
|------|------|----------|----------|
| RG001 | Read-modify-write | database | High |
| RG002 | Check-then-act | database | High |
| RG003 | Check-then-create | database | High |
| RG004 | Check-then-decrement (balances, stock, coins, quotas) | inventory | High |
| RG005 | Unsafe status/state transition | database | High |
| RG006 | Missing idempotency (queued external side effect) | idempotency | Medium |
| RG007 | Transaction without lock | transactions | High |
| RG008 | External side effect before commit | external | Medium |
| RG009 | Duplicate job processing | queue | Medium |
| RG010 | Missing database uniqueness (advisory) | idempotency | Low |
| RG011 | Non-atomic counter (views, likes, attempts) | database | Medium |
| RG012 | Guard-then-save | payments | High |
| RG013 | Non-atomic cache read-modify-write | cache | Medium |

Each rule can be toggled individually in `config/race-guard.php`.

### Safe patterns RaceGuard recognises

RaceGuard also understands common safe Laravel patterns and stays quiet on them:

- `lockForUpdate()`, `sharedLock()`
- `increment()` / `decrement()`
- `Cache::lock()`
- Atomic conditional updates (`->where('col', '>=', $n)->decrement(...)`)
- `firstOrCreate()` / `updateOrCreate()` / `insertOrIgnore()`
- `ShouldBeUnique` and the `WithoutOverlapping` middleware
- `dispatch()->afterCommit()`

## Categories

Findings are grouped into ten concurrency domains:

```text
payments   inventory   database   transactions   locks
idempotency   queue   scheduler   cache   external
```

You can report only the categories you care about with `--category` (repeatable).

## Requirements

Laravel RaceGuard is intended to support:

- PHP 8.2+
- Laravel 11
- Laravel 12
- Laravel 13

## Installation

Install Laravel RaceGuard using Composer:

```bash
composer require nayvocode/laravel-race-logic-guard --dev
```

The `--dev` flag is recommended because RaceGuard is a development/static-analysis tool and normally does not need to be installed in production.

Laravel package auto-discovery will automatically register RaceGuard.

## Usage

Check the current Git changes for potential race conditions:

```bash
php artisan race:check
```

By default RaceGuard scans the **whole of each changed file**. Common variants:

```bash
php artisan race:check                       # Git-aware scan of changed files
php artisan race:check --path=app/Services   # scan specific files or directories
php artisan race:check --all                 # scan the configured paths in full
php artisan race:check --staged              # only files staged for commit
php artisan race:check --changed-lines       # only report on changed lines
php artisan race:check --category=payments   # filter by category (repeatable)
php artisan race:check --severity=high       # only High and above
php artisan race:check --format=json         # machine-readable output for CI
```

RaceGuard analyzes the modified PHP files and reports suspicious concurrency patterns, grouped by category and tagged with their RG code.

Example:

```text
Laravel RaceGuard
────────────────────────────────────────

Scanned 1 changed PHP file...

DATABASE

RG001  HIGH
app/Services/WalletService.php:42

Potential read-modify-write race condition.

    $user->balance = $user->balance - $amount;
    $user->save();

The value of $user->balance is read and subsequently written
without an atomic operation or database lock.

Suggestion:
Use an atomic database update, e.g.
Model::whereKey($id)->decrement('balance', $amount), or lock the
row with lockForUpdate() inside a transaction before updating.

────────────────────────────────────────

1 potential race condition found.
```

## What RaceGuard Checks

RaceGuard focuses on patterns where concurrent execution could cause unexpected application state. A few representative examples follow; see [`RULES.md`](RULES.md) for the complete list.

### Read-Modify-Write (RG001)

Potentially unsafe:

```php
$user = User::find($userId);

$user->balance = $user->balance - $amount;

$user->save();
```

Two processes may read the same original value and overwrite each other's changes.

A safer approach may be:

```php
User::whereKey($userId)->decrement('balance', $amount);
```

### Check-Then-Decrement (RG004)

Potentially unsafe — the sufficiency check and the debit are separate steps:

```php
$wallet = PlayerCoin::where('player_id', $id)->first();

if (! $wallet || $wallet->coins < $amount) {
    abort(422);
}

$wallet->decrement('coins', $amount); // atomic, but the check was stale
```

A safer approach makes the check and the debit one atomic statement:

```php
$debited = PlayerCoin::where('player_id', $id)
    ->where('coins', '>=', $amount)
    ->decrement('coins', $amount);

if ($debited === 0) {
    abort(422); // insufficient — checked atomically
}
```

### Transaction Without Lock (RG007)

A transaction alone does not prevent two requests from reading the same row and overwriting each other on the default isolation level. Lock the row inside the transaction:

```php
DB::transaction(function () use ($id, $amount) {
    $wallet = Wallet::whereKey($id)->lockForUpdate()->firstOrFail();

    $wallet->balance -= $amount;
    $wallet->save();
});
```

### External Side Effect Before Commit (RG008)

An HTTP call, mail, notification or job dispatch inside a transaction runs before it commits — on rollback it cannot be undone, and a dispatched job may start before its data is committed. Defer it:

```php
DB::transaction(function () use ($order) {
    $order->update(['status' => 'paid']);
});

// after the transaction commits
Http::post('https://gateway/charge', [...]);
```

## Git-Aware Analysis

RaceGuard is designed to focus primarily on code you are currently changing.

It can inspect:

- Modified files
- Staged files
- Unstaged files
- New/untracked PHP files

By default a changed file is analysed **in full** (`diff_granularity => 'file'`). Pass `--changed-lines` (or set the config to `'lines'`) to restrict findings to the exact lines in your diff.

This prevents developers from being overwhelmed by unrelated warnings from existing code.

The goal is simple:

```text
Change code
    ↓
Run RaceGuard
    ↓
Fix potential concurrency problems
    ↓
Commit
```

## How It Works

RaceGuard does not execute your application.

It performs static analysis on your PHP source code.

The analysis pipeline is approximately:

```text
Git changes
     ↓
Changed PHP files
     ↓
PHP AST parser
     ↓
Code/data-flow analysis
     ↓
Race-condition rules
     ↓
Safety checks
     ↓
Findings
```

RaceGuard uses [`nikic/php-parser`](https://github.com/nikic/PHP-Parser) to parse PHP source code into an Abstract Syntax Tree (AST).

This allows RaceGuard to reason about PHP code structurally instead of relying on regular-expression matching.

## Severity Levels

Findings may be classified by severity:

| Severity | Meaning |
|----------|---------|
| Critical | Very high-risk concurrency issue |
| High | Strong indication of a race condition |
| Medium | Potentially unsafe concurrent operation |
| Low | Suspicious pattern that may require review |

Static analysis cannot always determine the application's runtime behavior, so a RaceGuard finding should be treated as a warning requiring developer review rather than proof that a race condition will occur.

## Configuration

RaceGuard configuration can be published with:

```bash
php artisan vendor:publish --tag=race-guard-config
```

This will create:

```text
config/race-guard.php
```

Example configuration:

```php
return [

    'paths' => [
        'app',
        // 'modules', // add if your business logic lives outside app/
    ],

    'exclude' => [
        'vendor',
        'storage',
        'bootstrap/cache',
        'node_modules',
    ],

    // Findings below this severity are hidden.
    'minimum_severity' => 'medium',

    // Exit non-zero when a finding of at least this severity is present.
    'fail_on' => 'high',

    // 'file' scans the whole changed file; 'lines' narrows to changed lines.
    'diff_granularity' => 'file',

    // Which Git changes to inspect: 'dirty', 'staged' or 'unstaged'.
    'git_scope' => 'dirty',

    // Toggle individual rules (RG001–RG013) on or off.
    'rules' => [
        'read_modify_write' => true,
        'check_then_act' => true,
        'check_then_create' => true,
        'unsafe_balance_update' => true,
        'unsafe_state_transition' => true,
        'missing_idempotency' => true,
        'transaction_without_lock' => true,
        'external_side_effect_before_commit' => true,
        'overlapping_job' => true,
        'missing_database_uniqueness' => true,
        'non_atomic_counter' => true,
        'guard_then_save' => true,
        'non_atomic_cache' => true,
    ],

];
```

## Git Pre-Commit Hook

RaceGuard is intended to work well as a Git pre-commit check.

For example:

```bash
php artisan race:check
```

can be executed before allowing a commit. The command exits non-zero when a finding of at least `fail_on` severity is present, so it can block a commit or fail CI.

A future version may provide automatic Git hook installation.

## CI/CD

RaceGuard is also intended for CI pipelines and supports machine-readable JSON:

```bash
php artisan race:check --format=json
```

The package ships a GitHub Actions workflow (`.github/workflows/run-tests.yml`) that runs the test suite and static analysis across PHP 8.2–8.4 and Laravel 11–12, and the same `race:check` command can run in GitHub Actions, GitLab CI, Bitbucket Pipelines, or another CI/CD workflow.

## Philosophy

RaceGuard aims to prioritize useful, high-confidence findings instead of reporting every theoretical concurrency issue.

The project follows several principles:

1. Prefer low false-positive rates.
2. Understand Laravel-specific patterns.
3. Recognize both unsafe and safe concurrency patterns.
4. Focus on code currently being changed.
5. Explain why a pattern may be dangerous.
6. Suggest possible fixes without assuming there is only one correct solution.

## Limitations

Static analysis cannot prove every race condition.

Whether code is safe may depend on:

- Database configuration
- Database isolation level
- Unique constraints
- Queue configuration
- Application architecture
- External services
- Distributed locks
- Code outside the analyzed method
- Runtime execution paths

RaceGuard should therefore be used as an additional development safeguard, not as a replacement for proper database constraints, transactions, locking strategies, testing, and code review.

## Roadmap

Implemented:

- [x] Git dirty-file detection
- [x] Changed-line detection
- [x] PHP AST parsing
- [x] Read-modify-write detection
- [x] Check-then-act detection
- [x] Check-then-create detection
- [x] Counter race detection
- [x] Laravel transaction awareness
- [x] `lockForUpdate()` awareness
- [x] Atomic update awareness
- [x] Laravel cache lock awareness
- [x] Configurable rules
- [x] Severity levels
- [x] Rule codes and categories
- [x] Category filtering (`--category`)
- [x] JSON output
- [x] GitHub Actions integration

Planned (see [`RULES.md`](RULES.md) for the full, categorized roadmap):

- [ ] Deeper queue concurrency analysis
- [ ] Lock-scope rules (lock-after-read, wrong-row lock, write outside the locked transaction)
- [ ] Scheduler overlap (`withoutOverlapping()`)
- [ ] Stale-model / lost-update detection and optimistic-locking hints
- [ ] Webhook / payment idempotency and outbox-pattern candidates
- [ ] Git pre-commit hook installation
- [ ] Custom user-defined rules

## Development

Clone the repository:

```bash
git clone https://github.com/nayvocode/laravel-race-logic-guard.git
cd laravel-race-logic-guard
```

Install dependencies:

```bash
composer install
```

Run the checks (Composer scripts are provided):

```bash
composer test      # phpunit
composer analyse   # phpstan
composer format    # pint (fix)
composer lint      # pint (check only)
composer check     # format + analyse + test
```

## Testing in a Local Laravel Application

During development, you can install RaceGuard into another Laravel application using a Composer path repository.

Add the following to the Laravel application's `composer.json`:

```json
"repositories": [
    {
        "type": "path",
        "url": "../laravel-race-logic-guard",
        "options": {
            "symlink": true
        }
    }
]
```

Then run:

```bash
composer require nayvocode/laravel-race-logic-guard:@dev --dev
```

Composer can symlink the local package into the Laravel application's `vendor` directory, allowing package changes to be tested without publishing a new release.

## Contributing

Contributions, bug reports, rule suggestions, and improvements are welcome.

If you discover a Laravel concurrency pattern that RaceGuard should detect, please open an issue with a minimal example demonstrating the unsafe pattern and, when possible, a corresponding safe implementation.

## Security

If you discover a security-related issue, please avoid publishing sensitive details in a public issue.

A dedicated security reporting process will be added as the project matures.

## License

Laravel RaceGuard is open-source software licensed under the [MIT License](LICENSE).

## Author

**Naveed**

Laravel RaceGuard is maintained under the Nayvo project.
