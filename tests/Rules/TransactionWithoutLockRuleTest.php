<?php

declare(strict_types=1);

namespace Nayvo\LaravelRaceGuard\Tests\Rules;

use Nayvo\LaravelRaceGuard\Rules\TransactionWithoutLockRule;
use Nayvo\LaravelRaceGuard\Support\Severity;
use Nayvo\LaravelRaceGuard\Tests\AnalyzerTestCase;
use PHPUnit\Framework\Attributes\Test;

final class TransactionWithoutLockRuleTest extends AnalyzerTestCase
{
    #[Test]
    public function it_flags_a_read_modify_write_inside_a_transaction_without_a_lock(): void
    {
        $findings = $this->analyze(
            <<<'PHP'
            DB::transaction(function () use ($id, $amount) {
                $wallet = Wallet::find($id);
                $wallet->balance = $wallet->balance - $amount;
                $wallet->save();
            });
            PHP,
            new TransactionWithoutLockRule,
        );

        $this->assertCount(1, $findings);
        $this->assertSame('transaction_without_lock', $findings[0]->rule);
        $this->assertSame(Severity::HIGH, $findings[0]->severity);
    }

    #[Test]
    public function it_is_silent_when_the_row_is_locked_inside_the_transaction(): void
    {
        $findings = $this->analyze(
            <<<'PHP'
            DB::transaction(function () use ($id, $amount) {
                $wallet = Wallet::whereKey($id)->lockForUpdate()->firstOrFail();
                $wallet->balance = $wallet->balance - $amount;
                $wallet->save();
            });
            PHP,
            new TransactionWithoutLockRule,
        );

        $this->assertSame([], $findings);
    }

    #[Test]
    public function it_does_not_flag_a_read_only_transaction(): void
    {
        $findings = $this->analyze(
            <<<'PHP'
            DB::transaction(function () use ($id) {
                $wallet = Wallet::find($id);
                return $wallet->balance;
            });
            PHP,
            new TransactionWithoutLockRule,
        );

        $this->assertSame([], $findings);
    }

    #[Test]
    public function it_does_not_flag_a_transaction_with_only_atomic_writes(): void
    {
        $findings = $this->analyze(
            <<<'PHP'
            DB::transaction(function () use ($id, $amount) {
                Wallet::whereKey($id)->decrement('balance', $amount);
            });
            PHP,
            new TransactionWithoutLockRule,
        );

        $this->assertSame([], $findings);
    }
}
