<?php

declare(strict_types=1);

namespace Nayvo\LaravelRaceGuard\Tests\Rules;

use Nayvo\LaravelRaceGuard\Rules\CheckThenCreateRule;
use Nayvo\LaravelRaceGuard\Support\Severity;
use Nayvo\LaravelRaceGuard\Tests\AnalyzerTestCase;
use PHPUnit\Framework\Attributes\Test;

final class CheckThenCreateRuleTest extends AnalyzerTestCase
{
    #[Test]
    public function it_flags_exists_then_create(): void
    {
        $findings = $this->analyze(
            <<<'PHP'
            if (! Order::where('reference', $reference)->exists()) {
                Order::create(['reference' => $reference]);
            }
            PHP,
            new CheckThenCreateRule,
        );

        $this->assertCount(1, $findings);
        $this->assertSame('check_then_create', $findings[0]->rule);
        $this->assertSame(Severity::HIGH, $findings[0]->severity);
    }

    #[Test]
    public function it_flags_a_null_first_lookup_then_new_and_save(): void
    {
        $findings = $this->analyze(
            <<<'PHP'
            if (Order::where('reference', $reference)->first() === null) {
                $order = new Order();
                $order->reference = $reference;
                $order->save();
            }
            PHP,
            new CheckThenCreateRule,
        );

        $this->assertCount(1, $findings);
    }

    #[Test]
    public function it_flags_a_zero_count_then_create(): void
    {
        $findings = $this->analyze(
            <<<'PHP'
            if (Order::where('reference', $reference)->count() === 0) {
                Order::create(['reference' => $reference]);
            }
            PHP,
            new CheckThenCreateRule,
        );

        $this->assertCount(1, $findings);
    }

    #[Test]
    public function it_does_not_flag_an_atomic_first_or_create(): void
    {
        $findings = $this->analyze(
            <<<'PHP'
            if (! Order::where('reference', $reference)->exists()) {
                Order::firstOrCreate(['reference' => $reference]);
            }
            PHP,
            new CheckThenCreateRule,
        );

        $this->assertSame([], $findings);
    }

    #[Test]
    public function it_does_not_flag_an_existence_check_without_a_create(): void
    {
        $findings = $this->analyze(
            <<<'PHP'
            if (! Order::where('reference', $reference)->exists()) {
                abort(404);
            }
            PHP,
            new CheckThenCreateRule,
        );

        $this->assertSame([], $findings);
    }

    #[Test]
    public function it_is_silent_when_a_cache_lock_guards_the_block(): void
    {
        $findings = $this->analyze(
            <<<'PHP'
            Cache::lock('orders:' . $reference)->get(function () use ($reference) {
                if (! Order::where('reference', $reference)->exists()) {
                    Order::create(['reference' => $reference]);
                }
            });
            PHP,
            new CheckThenCreateRule,
        );

        $this->assertSame([], $findings);
    }
}
