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

    #[Test]
    public function it_flags_an_instance_update_after_a_check_of_that_instance(): void
    {
        $findings = $this->analyze(
            <<<'PHP'
            $order = Order::find($id);
            if ($order->status !== 'pending') {
                return;
            }
            $order->update(['status' => 'processing']);
            PHP,
            new UnsafeStateTransitionRule,
        );

        $this->assertCount(1, $findings);
    }

    #[Test]
    public function it_does_not_confuse_statuses_on_different_model_instances(): void
    {
        $findings = $this->analyze(
            <<<'PHP'
            if (! $rule->status) {
                return;
            }
            $conversion = Conversion::create(['status' => 'pending']);
            $conversion->update(['status' => 'failed']);
            PHP,
            new UnsafeStateTransitionRule,
        );

        $this->assertSame([], $findings);
    }

    #[Test]
    public function it_does_not_confuse_a_status_query_on_another_model_with_an_instance_update(): void
    {
        $findings = $this->analyze(
            <<<'PHP'
            AuditLog::where('status', 'open')->first();
            $conversion = Conversion::create(['status' => 'pending']);
            $conversion->update(['status' => 'failed']);
            PHP,
            new UnsafeStateTransitionRule,
        );

        $this->assertSame([], $findings);
    }

    #[Test]
    public function it_does_not_confuse_a_status_check_on_another_model_with_a_builder_update(): void
    {
        $findings = $this->analyze(
            <<<'PHP'
            $rule = Rule::find($id);
            if (! $rule->status) {
                return;
            }
            $conversion = Conversion::find($id);
            Conversion::whereKey($id)->update(['status' => 'failed']);
            PHP,
            new UnsafeStateTransitionRule,
        );

        $this->assertSame([], $findings);
    }

    #[Test]
    public function it_flags_a_builder_update_using_an_id_where_clause_for_the_checked_record(): void
    {
        $findings = $this->analyze(
            <<<'PHP'
            $order = Order::find($id);
            if ($order->status !== 'pending') {
                return;
            }
            Order::where('id', $id)->update(['status' => 'processing']);
            PHP,
            new UnsafeStateTransitionRule,
        );

        $this->assertCount(1, $findings);
    }

    #[Test]
    public function it_does_not_treat_a_later_check_as_a_check_then_act_pattern(): void
    {
        $findings = $this->analyze(
            <<<'PHP'
            $order = Order::find($id);
            $order->update(['status' => 'processing']);
            if ($order->status === 'processing') {
                return;
            }
            PHP,
            new UnsafeStateTransitionRule,
        );

        $this->assertSame([], $findings);
    }
}
