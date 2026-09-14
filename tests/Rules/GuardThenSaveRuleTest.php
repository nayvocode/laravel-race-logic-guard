<?php

declare(strict_types=1);

namespace Nayvo\LaravelRaceGuard\Tests\Rules;

use Nayvo\LaravelRaceGuard\Rules\GuardThenSaveRule;
use Nayvo\LaravelRaceGuard\Support\Severity;
use Nayvo\LaravelRaceGuard\Tests\AnalyzerTestCase;
use PHPUnit\Framework\Attributes\Test;

final class GuardThenSaveRuleTest extends AnalyzerTestCase
{
    #[Test]
    public function it_flags_a_guard_then_reassign_and_save(): void
    {
        $findings = $this->analyze(
            <<<'PHP'
            $wallet = Wallet::find($id);
            if ($wallet->balance < $amount) {
                return;
            }
            $wallet->balance = $newBalance;
            $wallet->save();
            PHP,
            new GuardThenSaveRule,
        );

        $this->assertCount(1, $findings);
        $this->assertSame('guard_then_save', $findings[0]->rule);
        $this->assertSame(Severity::HIGH, $findings[0]->severity);
    }

    #[Test]
    public function it_leaves_self_referential_arithmetic_to_the_read_modify_write_rule(): void
    {
        $findings = $this->analyze(
            <<<'PHP'
            $wallet = Wallet::find($id);
            if ($wallet->balance < $amount) {
                return;
            }
            $wallet->balance = $wallet->balance - $amount;
            $wallet->save();
            PHP,
            new GuardThenSaveRule,
        );

        $this->assertSame([], $findings);
    }

    #[Test]
    public function it_is_silent_when_the_row_is_locked(): void
    {
        $findings = $this->analyze(
            <<<'PHP'
            $wallet = Wallet::whereKey($id)->lockForUpdate()->first();
            if ($wallet->balance < $amount) {
                return;
            }
            $wallet->balance = $newBalance;
            $wallet->save();
            PHP,
            new GuardThenSaveRule,
        );

        $this->assertSame([], $findings);
    }

    #[Test]
    public function it_does_not_flag_a_reassignment_that_is_never_saved(): void
    {
        $findings = $this->analyze(
            <<<'PHP'
            $wallet = Wallet::find($id);
            if ($wallet->balance < $amount) {
                return;
            }
            $wallet->balance = $newBalance;
            return $wallet->balance;
            PHP,
            new GuardThenSaveRule,
        );

        $this->assertSame([], $findings);
    }
}
