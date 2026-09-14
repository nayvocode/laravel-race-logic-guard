<?php

declare(strict_types=1);

namespace Nayvo\LaravelRaceGuard\Tests\Rules;

use Nayvo\LaravelRaceGuard\Rules\MissingIdempotencyRule;
use Nayvo\LaravelRaceGuard\Support\Severity;
use Nayvo\LaravelRaceGuard\Tests\AnalyzerTestCase;
use PHPUnit\Framework\Attributes\Test;

final class MissingIdempotencyRuleTest extends AnalyzerTestCase
{
    #[Test]
    public function it_flags_a_queued_job_with_an_external_effect_and_no_idempotency(): void
    {
        $findings = $this->analyze(
            <<<'PHP'
            <?php
            class ChargeCustomer implements ShouldQueue
            {
                public function handle(): void
                {
                    Http::post('https://gateway/charge', ['amount' => $this->amount]);
                }
            }
            PHP,
            new MissingIdempotencyRule,
        );

        $this->assertCount(1, $findings);
        $this->assertSame('missing_idempotency', $findings[0]->rule);
        $this->assertSame('RG006', $findings[0]->code);
        $this->assertSame(Severity::MEDIUM, $findings[0]->severity);
    }

    #[Test]
    public function it_is_silent_when_an_idempotency_key_is_used(): void
    {
        $findings = $this->analyze(
            <<<'PHP'
            <?php
            class ChargeCustomer implements ShouldQueue
            {
                public function handle(): void
                {
                    Http::post('https://gateway/charge', ['idempotency_key' => $this->key]);
                }
            }
            PHP,
            new MissingIdempotencyRule,
        );

        $this->assertSame([], $findings);
    }

    #[Test]
    public function it_is_silent_for_a_should_be_unique_job(): void
    {
        $findings = $this->analyze(
            <<<'PHP'
            <?php
            class ChargeCustomer implements ShouldQueue, ShouldBeUnique
            {
                public function handle(): void
                {
                    Http::post('https://gateway/charge', []);
                }
            }
            PHP,
            new MissingIdempotencyRule,
        );

        $this->assertSame([], $findings);
    }

    #[Test]
    public function it_does_not_flag_a_non_queued_class(): void
    {
        $findings = $this->analyze(
            <<<'PHP'
            <?php
            class GatewayClient
            {
                public function handle(): void
                {
                    Http::post('https://gateway/charge', []);
                }
            }
            PHP,
            new MissingIdempotencyRule,
        );

        $this->assertSame([], $findings);
    }
}
