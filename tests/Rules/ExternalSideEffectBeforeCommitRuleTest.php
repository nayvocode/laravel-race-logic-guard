<?php

declare(strict_types=1);

namespace Nayvo\LaravelRaceGuard\Tests\Rules;

use Nayvo\LaravelRaceGuard\Rules\ExternalSideEffectBeforeCommitRule;
use Nayvo\LaravelRaceGuard\Support\Severity;
use Nayvo\LaravelRaceGuard\Tests\AnalyzerTestCase;
use PHPUnit\Framework\Attributes\Test;

final class ExternalSideEffectBeforeCommitRuleTest extends AnalyzerTestCase
{
    #[Test]
    public function it_flags_an_http_call_inside_a_transaction(): void
    {
        $findings = $this->analyze(
            <<<'PHP'
            DB::transaction(function () use ($order) {
                $order->update(['status' => 'paid']);
                Http::post('https://gateway/charge', ['id' => $order->id]);
            });
            PHP,
            new ExternalSideEffectBeforeCommitRule,
        );

        $this->assertCount(1, $findings);
        $this->assertSame('external_side_effect_before_commit', $findings[0]->rule);
        $this->assertSame('RG008', $findings[0]->code);
        $this->assertSame(Severity::MEDIUM, $findings[0]->severity);
    }

    #[Test]
    public function it_flags_a_job_dispatch_inside_a_transaction(): void
    {
        $findings = $this->analyze(
            <<<'PHP'
            DB::transaction(function () use ($order) {
                $order->save();
                dispatch(new SendReceipt($order));
            });
            PHP,
            new ExternalSideEffectBeforeCommitRule,
        );

        $this->assertCount(1, $findings);
    }

    #[Test]
    public function it_is_silent_for_a_dispatch_deferred_to_after_commit(): void
    {
        $findings = $this->analyze(
            <<<'PHP'
            DB::transaction(function () use ($order) {
                $order->save();
                dispatch(new SendReceipt($order))->afterCommit();
            });
            PHP,
            new ExternalSideEffectBeforeCommitRule,
        );

        $this->assertSame([], $findings);
    }

    #[Test]
    public function it_does_not_flag_a_transaction_with_no_external_effects(): void
    {
        $findings = $this->analyze(
            <<<'PHP'
            DB::transaction(function () use ($order) {
                $order->save();
                $order->items()->update(['locked' => true]);
            });
            PHP,
            new ExternalSideEffectBeforeCommitRule,
        );

        $this->assertSame([], $findings);
    }
}
