<?php

declare(strict_types=1);

namespace Nayvo\LaravelRaceGuard\Support;

/**
 * The concurrency domains a rule can belong to. Used to group report output
 * and to power `race:check --category=...`.
 */
final class Category
{
    public const DATABASE = 'database';

    public const QUEUE = 'queue';

    public const CACHE = 'cache';

    public const SCHEDULER = 'scheduler';

    public const PAYMENTS = 'payments';

    public const INVENTORY = 'inventory';

    public const IDEMPOTENCY = 'idempotency';

    public const TRANSACTIONS = 'transactions';

    public const LOCKS = 'locks';

    public const EXTERNAL = 'external';

    /**
     * All categories, in the order they should appear in a grouped report.
     *
     * @return array<int, string>
     */
    public static function all(): array
    {
        return [
            self::PAYMENTS,
            self::INVENTORY,
            self::DATABASE,
            self::TRANSACTIONS,
            self::LOCKS,
            self::IDEMPOTENCY,
            self::QUEUE,
            self::SCHEDULER,
            self::CACHE,
            self::EXTERNAL,
        ];
    }

    public static function isValid(string $category): bool
    {
        return in_array($category, self::all(), true);
    }

    /**
     * Human-readable label, e.g. "external" => "External Side Effects".
     */
    public static function label(string $category): string
    {
        return match ($category) {
            self::EXTERNAL => 'External Side Effects',
            default => ucfirst($category),
        };
    }
}
