# Laravel RaceGuard

Laravel RaceGuard is a static analysis tool for detecting potential race conditions in Laravel applications.

It focuses on changed Git files, allowing developers to catch concurrency issues before they are committed or deployed.

```bash
php artisan race:check
```

> **Status:** Laravel RaceGuard is currently under development. APIs, rules, and configuration may change before the first stable release.

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

## Features

Laravel RaceGuard is designed to detect common Laravel concurrency problems, including:

- Read-modify-write operations
- Check-then-act patterns
- Check-then-create patterns
- Non-atomic counters
- Potential duplicate record creation
- Missing database locks
- Unsafe model state transitions
- Unsafe balance and inventory updates

RaceGuard also understands common safe Laravel patterns such as:

- `lockForUpdate()`
- `increment()`
- `decrement()`
- `Cache::lock()`
- Atomic database updates

> Not all rules listed above may be available in early development versions.

## Requirements

Laravel RaceGuard is intended to support:

- PHP 8.2+
- Laravel 11
- Laravel 12
- Laravel 13

## Installation

Install Laravel RaceGuard using Composer:

```bash
composer require nayvo/laravel-race-guard --dev
```

The `--dev` flag is recommended because RaceGuard is a development/static-analysis tool and normally does not need to be installed in production.

Laravel package auto-discovery will automatically register RaceGuard.

## Usage

Check the current Git changes for potential race conditions:

```bash
php artisan race:check
```

RaceGuard analyzes modified PHP files and reports suspicious concurrency patterns.

Example:

```text
Laravel RaceGuard
────────────────────────────────────────

Scanning changed PHP files...

HIGH
app/Services/WalletService.php:42

Potential read-modify-write race condition.

$user->balance = $user->balance - $amount;
$user->save();

The value is read and subsequently written without an
atomic operation or database lock.

Suggestion:
Use an atomic database operation or lock the row before
performing the update.

────────────────────────────────────────

1 potential race condition found.
```

## What RaceGuard Checks

RaceGuard focuses on patterns where concurrent execution could cause unexpected application state.

### Read-Modify-Write

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

The correct solution depends on the surrounding business logic.

### Check-Then-Act

Potentially unsafe:

```php
$order = Order::find($orderId);

if ($order->status === 'pending') {
    $order->status = 'processing';
    $order->save();

    // Process order...
}
```

Multiple workers could observe the order in the `pending` state before either worker changes it.

Depending on the operation, a database transaction with a row lock may be appropriate:

```php
DB::transaction(function () use ($orderId) {
    $order = Order::whereKey($orderId)
        ->lockForUpdate()
        ->firstOrFail();

    if ($order->status === 'pending') {
        $order->status = 'processing';
        $order->save();

        // Process order...
    }
});
```

### Check-Then-Create

Potentially unsafe:

```php
if (!Order::where('reference', $reference)->exists()) {
    Order::create([
        'reference' => $reference,
    ]);
}
```

Two requests could both determine that the record does not exist and then both create it.

For uniqueness requirements, a database unique constraint should normally be the final line of defense.

### Non-Atomic Counters

Potentially unsafe:

```php
$post->views = $post->views + 1;
$post->save();
```

A safer atomic operation is:

```php
$post->increment('views');
```

## Git-Aware Analysis

RaceGuard is designed to focus primarily on code you are currently changing.

It can inspect:

- Modified files
- Staged files
- Unstaged files
- New/untracked PHP files

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
    ],

    'exclude' => [
        'vendor',
        'storage',
        'bootstrap/cache',
    ],

    'minimum_severity' => 'medium',

];
```

Configuration options may expand as RaceGuard develops.

## Git Pre-Commit Hook

RaceGuard is intended to work well as a Git pre-commit check.

For example:

```bash
php artisan race:check
```

can be executed before allowing a commit.

A future version may provide automatic Git hook installation.

Example workflow:

```text
git commit
     ↓
Laravel RaceGuard
     ↓
Potential race found?
     │
   YES ──→ Stop / review
     │
    NO
     ↓
Commit
```

## CI/CD

RaceGuard is also intended for CI pipelines.

For example:

```bash
php artisan race:check
```

can be run during GitHub Actions, GitLab CI, Bitbucket Pipelines, or another CI/CD workflow.

Future versions may support machine-readable output such as:

```bash
php artisan race:check --format=json
```

for integration with automated code-review systems.

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

Planned functionality includes:

- [ ] Git dirty-file detection
- [ ] Changed-line detection
- [ ] PHP AST parsing
- [ ] Read-modify-write detection
- [ ] Check-then-act detection
- [ ] Check-then-create detection
- [ ] Counter race detection
- [ ] Laravel transaction awareness
- [ ] `lockForUpdate()` awareness
- [ ] Atomic update awareness
- [ ] Laravel cache lock awareness
- [ ] Queue concurrency analysis
- [ ] Configurable rules
- [ ] Severity levels
- [ ] JSON output
- [ ] Git pre-commit integration
- [ ] GitHub Actions integration
- [ ] Custom user-defined rules

## Development

Clone the repository:

```bash
git clone https://github.com/nayvo/laravel-race-guard.git
cd laravel-race-guard
```

Install dependencies:

```bash
composer install
```

Run tests:

```bash
vendor/bin/phpunit
```

Run static analysis:

```bash
vendor/bin/phpstan analyse
```

Run code formatting:

```bash
vendor/bin/pint
```

## Testing in a Local Laravel Application

During development, you can install RaceGuard into another Laravel application using a Composer path repository.

Add the following to the Laravel application's `composer.json`:

```json
"repositories": [
    {
        "type": "path",
        "url": "../laravel-race-guard",
        "options": {
            "symlink": true
        }
    }
]
```

Then run:

```bash
composer require nayvo/laravel-race-guard:@dev --dev
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