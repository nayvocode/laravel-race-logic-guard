<?php

declare(strict_types=1);

namespace Nayvo\LaravelRaceGuard\Tests\Rules;

use Nayvo\LaravelRaceGuard\Rules\NonAtomicCacheRule;
use Nayvo\LaravelRaceGuard\Support\Severity;
use Nayvo\LaravelRaceGuard\Tests\AnalyzerTestCase;
use PHPUnit\Framework\Attributes\Test;

final class NonAtomicCacheRuleTest extends AnalyzerTestCase
{
    #[Test]
    public function it_flags_a_cache_get_then_arithmetic_put(): void
    {
        $findings = $this->analyze(
            <<<'PHP'
            $count = Cache::get($key, 0);
            Cache::put($key, $count + 1);
            PHP,
            new NonAtomicCacheRule,
        );

        $this->assertCount(1, $findings);
        $this->assertSame('non_atomic_cache', $findings[0]->rule);
        $this->assertSame(Severity::MEDIUM, $findings[0]->severity);
    }

    #[Test]
    public function it_is_silent_for_the_atomic_increment_helper(): void
    {
        $findings = $this->analyze(
            'Cache::increment($key);',
            new NonAtomicCacheRule,
        );

        $this->assertSame([], $findings);
    }

    #[Test]
    public function it_does_not_flag_a_plain_put_without_arithmetic(): void
    {
        $findings = $this->analyze(
            <<<'PHP'
            $value = Cache::get($key);
            Cache::put($key, $value);
            PHP,
            new NonAtomicCacheRule,
        );

        $this->assertSame([], $findings);
    }

    #[Test]
    public function it_is_silent_when_guarded_by_a_cache_lock(): void
    {
        $findings = $this->analyze(
            <<<'PHP'
            Cache::lock('counter')->get(function () use ($key) {
                $count = Cache::get($key, 0);
                Cache::put($key, $count + 1);
            });
            PHP,
            new NonAtomicCacheRule,
        );

        $this->assertSame([], $findings);
    }
}
