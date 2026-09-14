<?php

declare(strict_types=1);

namespace Nayvo\LaravelRaceGuard\Rules;

use Nayvo\LaravelRaceGuard\Support\Category;

/**
 * Central registry mapping each rule id to its stable RG code and category.
 * Keeping this in one place lets findings, the reporter and the docs stay in
 * sync, and gives the project a single source of truth for its rule identity.
 */
final class RuleMeta
{
    /**
     * id => [code, category].
     *
     * @var array<string, array{code: string, category: string}>
     */
    private const META = [
        'read_modify_write' => ['code' => 'RG001', 'category' => Category::DATABASE],
        'check_then_act' => ['code' => 'RG002', 'category' => Category::DATABASE],
        'check_then_create' => ['code' => 'RG003', 'category' => Category::DATABASE],
        'unsafe_balance_update' => ['code' => 'RG004', 'category' => Category::INVENTORY],
        'unsafe_state_transition' => ['code' => 'RG005', 'category' => Category::DATABASE],
        'missing_idempotency' => ['code' => 'RG006', 'category' => Category::IDEMPOTENCY],
        'transaction_without_lock' => ['code' => 'RG007', 'category' => Category::TRANSACTIONS],
        'external_side_effect_before_commit' => ['code' => 'RG008', 'category' => Category::EXTERNAL],
        'overlapping_job' => ['code' => 'RG009', 'category' => Category::QUEUE],
        'missing_database_uniqueness' => ['code' => 'RG010', 'category' => Category::IDEMPOTENCY],
        'non_atomic_counter' => ['code' => 'RG011', 'category' => Category::DATABASE],
        'guard_then_save' => ['code' => 'RG012', 'category' => Category::PAYMENTS],
        'non_atomic_cache' => ['code' => 'RG013', 'category' => Category::CACHE],
    ];

    public static function code(string $ruleId): string
    {
        return self::META[$ruleId]['code'] ?? 'RG000';
    }

    public static function category(string $ruleId): string
    {
        return self::META[$ruleId]['category'] ?? Category::DATABASE;
    }

    /**
     * @return array<string, array{code: string, category: string}>
     */
    public static function all(): array
    {
        return self::META;
    }
}
