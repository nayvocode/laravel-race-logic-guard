<?php

declare(strict_types=1);

namespace Nayvo\LaravelRaceGuard\Tests\Analysis;

use Nayvo\LaravelRaceGuard\Rules\NonAtomicCounterRule;
use Nayvo\LaravelRaceGuard\Tests\AnalyzerTestCase;
use PHPUnit\Framework\Attributes\Test;

final class ChangedLineScopeTest extends AnalyzerTestCase
{
    private string $source = <<<'PHP'
    <?php

    class Counter
    {
        public function bump($post)
        {
            $post->views = $post->views + 1;
            $post->save();
        }
    }
    PHP;

    #[Test]
    public function it_reports_a_finding_when_its_line_is_in_the_changed_set(): void
    {
        // The mutation is on line 7 of the source above.
        $findings = $this->analyze($this->source, new NonAtomicCounterRule, [7]);

        $this->assertCount(1, $findings);
        $this->assertSame(7, $findings[0]->line);
    }

    #[Test]
    public function it_suppresses_a_finding_outside_the_changed_set(): void
    {
        // Only unrelated lines changed, so the finding is out of scope.
        $findings = $this->analyze($this->source, new NonAtomicCounterRule, [1, 2, 3]);

        $this->assertSame([], $findings);
    }

    #[Test]
    public function a_null_changed_set_scans_the_whole_file(): void
    {
        $findings = $this->analyze($this->source, new NonAtomicCounterRule, null);

        $this->assertCount(1, $findings);
    }
}
