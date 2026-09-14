<?php

declare(strict_types=1);

namespace Nayvo\LaravelRaceGuard\Rules;

/**
 * Builds the set of active rules from the package configuration.
 */
final class RuleFactory
{
    /**
     * Map of config key => rule class.
     *
     * @var array<string, class-string<Rule>>
     */
    private const RULES = [
        'read_modify_write' => ReadModifyWriteRule::class,
        'check_then_act' => CheckThenActRule::class,
        'check_then_create' => CheckThenCreateRule::class,
        'unsafe_balance_update' => UnsafeBalanceUpdateRule::class,
        'unsafe_state_transition' => UnsafeStateTransitionRule::class,
        'missing_idempotency' => MissingIdempotencyRule::class,
        'transaction_without_lock' => TransactionWithoutLockRule::class,
        'external_side_effect_before_commit' => ExternalSideEffectBeforeCommitRule::class,
        'overlapping_job' => OverlappingJobRule::class,
        'missing_database_uniqueness' => MissingDatabaseUniquenessRule::class,
        'non_atomic_counter' => NonAtomicCounterRule::class,
        'guard_then_save' => GuardThenSaveRule::class,
        'non_atomic_cache' => NonAtomicCacheRule::class,
    ];

    /**
     * @param  array<string, bool>  $enabled  Config "rules" map (key => bool).
     * @return array<int, Rule>
     */
    public static function make(array $enabled = []): array
    {
        $rules = [];

        foreach (self::RULES as $key => $class) {
            // A rule is on unless it is explicitly disabled in config.
            if (($enabled[$key] ?? true) !== false) {
                $rules[] = new $class;
            }
        }

        return $rules;
    }

    /**
     * @return array<int, string>
     */
    public static function availableKeys(): array
    {
        return array_keys(self::RULES);
    }
}
