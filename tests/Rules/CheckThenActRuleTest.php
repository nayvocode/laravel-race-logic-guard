<?php

declare(strict_types=1);

namespace Nayvo\LaravelRaceGuard\Tests\Rules;

use Nayvo\LaravelRaceGuard\Rules\CheckThenActRule;
use Nayvo\LaravelRaceGuard\Support\Severity;
use Nayvo\LaravelRaceGuard\Tests\AnalyzerTestCase;
use PHPUnit\Framework\Attributes\Test;

final class CheckThenActRuleTest extends AnalyzerTestCase
{
    #[Test]
    public function it_flags_a_check_then_act_on_a_fetched_model(): void
    {
        $findings = $this->analyze(
            <<<'PHP'
            $order = Order::find($orderId);
            if ($order->status === 'pending') {
                $order->status = 'processing';
                $order->save();
            }
            PHP,
            new CheckThenActRule,
        );

        $this->assertCount(1, $findings);
        $this->assertSame('check_then_act', $findings[0]->rule);
        $this->assertSame(Severity::HIGH, $findings[0]->severity);
    }

    #[Test]
    public function it_ignores_the_pattern_inside_a_locked_transaction(): void
    {
        $findings = $this->analyze(
            <<<'PHP'
            $order = Order::whereKey($orderId)->lockForUpdate()->firstOrFail();
            if ($order->status === 'pending') {
                $order->status = 'processing';
                $order->save();
            }
            PHP,
            new CheckThenActRule,
        );

        $this->assertSame([], $findings);
    }

    #[Test]
    public function it_does_not_flag_a_check_without_a_model_read(): void
    {
        // $config is not a fetched model, so an inline mutate-and-save is
        // not the check-then-act shape this rule targets.
        $findings = $this->analyze(
            <<<'PHP'
            if ($config->enabled === true) {
                $config->enabled = false;
                $config->save();
            }
            PHP,
            new CheckThenActRule,
        );

        $this->assertSame([], $findings);
    }

    #[Test]
    public function it_does_not_flag_a_read_only_conditional(): void
    {
        $findings = $this->analyze(
            <<<'PHP'
            $order = Order::find($orderId);
            if ($order->status === 'pending') {
                return 'still pending';
            }
            PHP,
            new CheckThenActRule,
        );

        $this->assertSame([], $findings);
    }
}
