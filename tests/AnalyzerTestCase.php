<?php

declare(strict_types=1);

namespace Nayvo\LaravelRaceGuard\Tests;

use Nayvo\LaravelRaceGuard\Analysis\Analyzer;
use Nayvo\LaravelRaceGuard\Analysis\Finding;
use Nayvo\LaravelRaceGuard\Rules\Rule;
use Nayvo\LaravelRaceGuard\Support\Severity;
use PHPUnit\Framework\TestCase;

/**
 * Base test case for the pure static-analysis layer (no Laravel required).
 */
abstract class AnalyzerTestCase extends TestCase
{
    /**
     * Run one or more rules over a PHP source string.
     *
     * @param  Rule|array<int, Rule>  $rules
     * @param  array<int, int>|null  $changedLines
     * @return array<int, Finding>
     */
    protected function analyze(string $source, Rule|array $rules, ?array $changedLines = null): array
    {
        $rules = is_array($rules) ? $rules : [$rules];

        $analyzer = new Analyzer($rules, Severity::LOW);

        return $analyzer->analyzeSource('Example.php', $this->wrap($source), $changedLines);
    }

    /**
     * @param  array<int, Finding>  $findings
     */
    protected function assertRuleIds(array $expectedRuleIds, array $findings): void
    {
        $actual = array_map(static fn (Finding $f): string => $f->rule, $findings);

        sort($expectedRuleIds);
        sort($actual);

        $this->assertSame($expectedRuleIds, $actual);
    }

    /**
     * Wrap a bare code body in a minimal class method so line numbers are
     * stable and realistic.
     */
    private function wrap(string $body): string
    {
        // Allow tests to pass full files starting with <?php verbatim.
        if (str_starts_with(ltrim($body), '<?php')) {
            return $body;
        }

        return "<?php\n\nclass Example\n{\n    public function handle()\n    {\n{$body}\n    }\n}\n";
    }
}
