<?php

declare(strict_types=1);

namespace Nayvo\LaravelRaceGuard\Tests\Rules;

use Nayvo\LaravelRaceGuard\Rules\OverlappingJobRule;
use Nayvo\LaravelRaceGuard\Support\Severity;
use Nayvo\LaravelRaceGuard\Tests\AnalyzerTestCase;
use PHPUnit\Framework\Attributes\Test;

final class OverlappingJobRuleTest extends AnalyzerTestCase
{
    #[Test]
    public function it_flags_a_mutating_queued_job_without_overlap_protection(): void
    {
        $findings = $this->analyze(
            <<<'PHP'
            <?php
            class ProcessPayout implements ShouldQueue
            {
                public function handle(): void
                {
                    $wallet = Wallet::find($this->id);
                    $wallet->decrement('balance', $this->amount);
                }
            }
            PHP,
            new OverlappingJobRule,
        );

        $this->assertCount(1, $findings);
        $this->assertSame('overlapping_job', $findings[0]->rule);
        $this->assertSame(Severity::MEDIUM, $findings[0]->severity);
    }

    #[Test]
    public function it_is_silent_for_a_should_be_unique_job(): void
    {
        $findings = $this->analyze(
            <<<'PHP'
            <?php
            class ProcessPayout implements ShouldQueue, ShouldBeUnique
            {
                public function handle(): void
                {
                    Wallet::find($this->id)->decrement('balance', $this->amount);
                }
            }
            PHP,
            new OverlappingJobRule,
        );

        $this->assertSame([], $findings);
    }

    #[Test]
    public function it_is_silent_when_a_without_overlapping_middleware_is_present(): void
    {
        $findings = $this->analyze(
            <<<'PHP'
            <?php
            class ProcessPayout implements ShouldQueue
            {
                public function middleware(): array
                {
                    return [new WithoutOverlapping($this->id)];
                }

                public function handle(): void
                {
                    Wallet::find($this->id)->decrement('balance', 1);
                }
            }
            PHP,
            new OverlappingJobRule,
        );

        $this->assertSame([], $findings);
    }

    #[Test]
    public function it_does_not_flag_a_read_only_job(): void
    {
        $findings = $this->analyze(
            <<<'PHP'
            <?php
            class SendReport implements ShouldQueue
            {
                public function handle(): int
                {
                    return Wallet::count();
                }
            }
            PHP,
            new OverlappingJobRule,
        );

        $this->assertSame([], $findings);
    }

    #[Test]
    public function it_does_not_flag_a_plain_non_queued_class(): void
    {
        $findings = $this->analyze(
            <<<'PHP'
            <?php
            class WalletService
            {
                public function handle(): void
                {
                    Wallet::find($this->id)->decrement('balance', 1);
                }
            }
            PHP,
            new OverlappingJobRule,
        );

        $this->assertSame([], $findings);
    }
}
