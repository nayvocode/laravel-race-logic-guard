<?php

declare(strict_types=1);

namespace Nayvo\LaravelRaceGuard\Tests\Rules;

use Nayvo\LaravelRaceGuard\Rules\ReadModifyWriteRule;
use Nayvo\LaravelRaceGuard\Support\Severity;
use Nayvo\LaravelRaceGuard\Tests\AnalyzerTestCase;
use PHPUnit\Framework\Attributes\Test;

final class ReadModifyWriteRuleTest extends AnalyzerTestCase
{
    #[Test]
    public function it_flags_a_read_modify_write_on_a_model_attribute(): void
    {
        $findings = $this->analyze(
            <<<'PHP'
            $user = User::find($userId);
            $user->balance = $user->balance - $amount;
            $user->save();
            PHP,
            new ReadModifyWriteRule,
        );

        $this->assertCount(1, $findings);
        $this->assertSame('read_modify_write', $findings[0]->rule);
        $this->assertSame(Severity::HIGH, $findings[0]->severity);
    }

    #[Test]
    public function it_ignores_the_pattern_when_the_row_is_locked_for_update(): void
    {
        $findings = $this->analyze(
            <<<'PHP'
            $user = User::whereKey($userId)->lockForUpdate()->first();
            $user->balance = $user->balance - $amount;
            $user->save();
            PHP,
            new ReadModifyWriteRule,
        );

        $this->assertSame([], $findings);
    }

    #[Test]
    public function it_ignores_an_atomic_decrement(): void
    {
        $findings = $this->analyze(
            'User::whereKey($userId)->decrement("balance", $amount);',
            new ReadModifyWriteRule,
        );

        $this->assertSame([], $findings);
    }

    #[Test]
    public function it_does_not_flag_a_calculation_that_is_never_persisted(): void
    {
        $findings = $this->analyze(
            <<<'PHP'
            $user = User::find($userId);
            $user->balance = $user->balance - $amount;
            return $user->balance;
            PHP,
            new ReadModifyWriteRule,
        );

        $this->assertSame([], $findings);
    }

    #[Test]
    public function it_does_not_flag_local_variable_accumulation(): void
    {
        $findings = $this->analyze(
            <<<'PHP'
            $total = 0;
            foreach ($items as $item) {
                $total = $total + $item->price;
            }
            PHP,
            new ReadModifyWriteRule,
        );

        $this->assertSame([], $findings);
    }

    #[Test]
    public function it_does_not_claim_a_constant_counter_increment(): void
    {
        // That belongs to the non-atomic counter rule, not this one.
        $findings = $this->analyze(
            <<<'PHP'
            $post->views = $post->views + 1;
            $post->save();
            PHP,
            new ReadModifyWriteRule,
        );

        $this->assertSame([], $findings);
    }
}
