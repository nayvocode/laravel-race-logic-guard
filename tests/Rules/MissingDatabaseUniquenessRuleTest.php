<?php

declare(strict_types=1);

namespace Nayvo\LaravelRaceGuard\Tests\Rules;

use Nayvo\LaravelRaceGuard\Rules\MissingDatabaseUniquenessRule;
use Nayvo\LaravelRaceGuard\Support\Severity;
use Nayvo\LaravelRaceGuard\Tests\AnalyzerTestCase;
use PHPUnit\Framework\Attributes\Test;

final class MissingDatabaseUniquenessRuleTest extends AnalyzerTestCase
{
    #[Test]
    public function it_hints_on_first_or_create(): void
    {
        $findings = $this->analyze(
            "User::firstOrCreate(['email' => \$email]);",
            new MissingDatabaseUniquenessRule,
        );

        $this->assertCount(1, $findings);
        $this->assertSame('missing_database_uniqueness', $findings[0]->rule);
        $this->assertSame('RG010', $findings[0]->code);
        $this->assertSame(Severity::LOW, $findings[0]->severity);
    }

    #[Test]
    public function it_hints_on_update_or_create(): void
    {
        $findings = $this->analyze(
            "Setting::updateOrCreate(['key' => \$key], ['value' => \$value]);",
            new MissingDatabaseUniquenessRule,
        );

        $this->assertCount(1, $findings);
    }

    #[Test]
    public function it_does_not_hint_on_a_plain_create(): void
    {
        $findings = $this->analyze(
            "User::create(['email' => \$email]);",
            new MissingDatabaseUniquenessRule,
        );

        $this->assertSame([], $findings);
    }
}
