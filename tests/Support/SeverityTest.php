<?php

declare(strict_types=1);

namespace Nayvo\LaravelRaceGuard\Tests\Support;

use Nayvo\LaravelRaceGuard\Support\Severity;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class SeverityTest extends TestCase
{
    #[Test]
    public function it_orders_severities_correctly(): void
    {
        $this->assertTrue(Severity::atLeast(Severity::CRITICAL, Severity::HIGH));
        $this->assertTrue(Severity::atLeast(Severity::HIGH, Severity::HIGH));
        $this->assertFalse(Severity::atLeast(Severity::LOW, Severity::MEDIUM));
        $this->assertFalse(Severity::atLeast(Severity::MEDIUM, Severity::HIGH));
    }

    #[Test]
    public function it_validates_severity_strings(): void
    {
        $this->assertTrue(Severity::isValid('high'));
        $this->assertFalse(Severity::isValid('urgent'));
    }

    #[Test]
    public function it_labels_severities_in_upper_case(): void
    {
        $this->assertSame('HIGH', Severity::label(Severity::HIGH));
    }
}
