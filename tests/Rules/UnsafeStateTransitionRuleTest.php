<?php

declare(strict_types=1);

namespace Nayvo\LaravelRaceGuard\Tests\Rules;

use Nayvo\LaravelRaceGuard\Rules\UnsafeStateTransitionRule;
use Nayvo\LaravelRaceGuard\Support\Severity;
use Nayvo\LaravelRaceGuard\Tests\AnalyzerTestCase;
use PHPUnit\Framework\Attributes\Test;

final class UnsafeStateTransitionRuleTest extends AnalyzerTestCase
{
    #[Test]
    public function it_flags_a_status_check_followed_by_a_blind_update(): void
    {
        $findings = $this->analyze(
            <<<'PHP'
            $order = Order::find($id);
            if ($order->status !== 'pending') {
                return;
            }
            Order::whereKey($id)->update(['status' => 'processing']);
            PHP,
            new UnsafeStateTransitionRule,
        );

        $this->assertCount(1, $findings);
        $this->assertSame('unsafe_state_transition', $findings[0]->rule);
        $this->assertSame('RG005', $findings[0]->code);
        $this->assertSame(Severity::HIGH, $findings[0]->severity);
    }

    #[Test]
    public function it_is_silent_for_a_conditional_first_wins_update(): void
    {
        $findings = $this->analyze(
            <<<'PHP'
            $order = Order::find($id);
            if ($order->status !== 'pending') {
                return;
            }
            Order::whereKey($id)->where('status', 'pending')->update(['status' => 'processing']);
            PHP,
            new UnsafeStateTransitionRule,
        );

        $this->assertSame([], $findings);
    }

    #[Test]
    public function it_does_not_flag_an_update_without_a_prior_status_check(): void
    {
        $findings = $this->analyze(
            "Order::whereKey(\$id)->update(['status' => 'processing']);",
            new UnsafeStateTransitionRule,
        );

        $this->assertSame([], $findings);
    }
}
