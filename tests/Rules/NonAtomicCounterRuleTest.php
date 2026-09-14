<?php

declare(strict_types=1);

namespace Nayvo\LaravelRaceGuard\Tests\Rules;

use Nayvo\LaravelRaceGuard\Rules\NonAtomicCounterRule;
use Nayvo\LaravelRaceGuard\Support\Severity;
use Nayvo\LaravelRaceGuard\Tests\AnalyzerTestCase;
use PHPUnit\Framework\Attributes\Test;

final class NonAtomicCounterRuleTest extends AnalyzerTestCase
{
    #[Test]
    public function it_flags_a_manual_counter_increment(): void
    {
        $findings = $this->analyze(
            <<<'PHP'
            $post->views = $post->views + 1;
            $post->save();
            PHP,
            new NonAtomicCounterRule,
        );

        $this->assertCount(1, $findings);
        $this->assertSame('non_atomic_counter', $findings[0]->rule);
        $this->assertSame(Severity::MEDIUM, $findings[0]->severity);
    }

    #[Test]
    public function it_flags_a_compound_increment_operator(): void
    {
        $findings = $this->analyze(
            <<<'PHP'
            $post->views += 1;
            $post->save();
            PHP,
            new NonAtomicCounterRule,
        );

        $this->assertCount(1, $findings);
    }

    #[Test]
    public function it_flags_a_post_increment(): void
    {
        $findings = $this->analyze(
            <<<'PHP'
            $post->views++;
            $post->save();
            PHP,
            new NonAtomicCounterRule,
        );

        $this->assertCount(1, $findings);
    }

    #[Test]
    public function it_ignores_the_atomic_increment_helper(): void
    {
        $findings = $this->analyze(
            '$post->increment("views");',
            new NonAtomicCounterRule,
        );

        $this->assertSame([], $findings);
    }

    #[Test]
    public function it_does_not_claim_a_variable_amount_mutation(): void
    {
        // Variable-amount mutations belong to the read-modify-write rule.
        $findings = $this->analyze(
            <<<'PHP'
            $wallet->balance = $wallet->balance + $amount;
            $wallet->save();
            PHP,
            new NonAtomicCounterRule,
        );

        $this->assertSame([], $findings);
    }
}
