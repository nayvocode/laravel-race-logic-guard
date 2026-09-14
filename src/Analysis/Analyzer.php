<?php

declare(strict_types=1);

namespace Nayvo\LaravelRaceGuard\Analysis;

use Nayvo\LaravelRaceGuard\Rules\Rule;
use Nayvo\LaravelRaceGuard\Support\Severity;
use PhpParser\Error;
use PhpParser\Node;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\ParentConnectingVisitor;
use PhpParser\Parser;
use PhpParser\ParserFactory;

/**
 * Parses PHP source into an AST and runs the configured rules against it.
 */
class Analyzer
{
    private Parser $parser;

    /**
     * @param  array<int, Rule>  $rules
     */
    public function __construct(
        private array $rules,
        private string $minimumSeverity = Severity::LOW,
    ) {
        $this->parser = (new ParserFactory)->createForNewestSupportedVersion();
    }

    /**
     * Analyse raw source. $changedLines restricts findings to those lines
     * (null = whole file). Returns findings sorted by severity then location.
     *
     * @param  array<int, int>|null  $changedLines
     * @return array<int, Finding>
     */
    public function analyzeSource(string $path, string $source, ?array $changedLines = null): array
    {
        $ast = $this->parse($source);

        if ($ast === null) {
            return [];
        }

        $context = new FileContext($path, $source, $ast, $changedLines);

        $findings = [];
        foreach ($this->rules as $rule) {
            foreach ($rule->analyze($context) as $finding) {
                if (Severity::atLeast($finding->severity, $this->minimumSeverity)) {
                    $findings[] = $finding;
                }
            }
        }

        return $this->sort($findings);
    }

    /**
     * Analyse a file on disk.
     *
     * @param  array<int, int>|null  $changedLines
     * @return array<int, Finding>
     */
    public function analyzeFile(string $absolutePath, ?string $displayPath = null, ?array $changedLines = null): array
    {
        if (! is_file($absolutePath) || ! is_readable($absolutePath)) {
            return [];
        }

        $source = (string) file_get_contents($absolutePath);

        return $this->analyzeSource($displayPath ?? $absolutePath, $source, $changedLines);
    }

    /**
     * @return array<int, Node>|null
     */
    private function parse(string $source): ?array
    {
        try {
            $stmts = $this->parser->parse($source);
        } catch (Error) {
            // A file that does not parse cannot be analysed; skip it quietly.
            return null;
        }

        if ($stmts === null) {
            return null;
        }

        $traverser = new NodeTraverser;
        $traverser->addVisitor(new ParentConnectingVisitor);

        return $traverser->traverse($stmts);
    }

    /**
     * @param  array<int, Finding>  $findings
     * @return array<int, Finding>
     */
    private function sort(array $findings): array
    {
        usort($findings, static function (Finding $a, Finding $b): int {
            return Severity::weight($b->severity) <=> Severity::weight($a->severity)
                ?: [$a->file, $a->line] <=> [$b->file, $b->line];
        });

        return $findings;
    }
}
