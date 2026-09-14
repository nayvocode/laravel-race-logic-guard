<?php

declare(strict_types=1);

namespace Nayvo\LaravelRaceGuard\Rules;

use Nayvo\LaravelRaceGuard\Analysis\FileContext;
use Nayvo\LaravelRaceGuard\Analysis\Finding;
use PhpParser\Node;
use PhpParser\NodeFinder;

abstract class AbstractRule implements Rule
{
    protected NodeFinder $finder;

    public function __construct()
    {
        $this->finder = new NodeFinder;
    }

    /**
     * Find every node of the given class within the file's AST.
     *
     * @template T of Node
     *
     * @param  class-string<T>  $class
     * @return array<int, T>
     */
    protected function findInstances(FileContext $file, string $class): array
    {
        /** @var array<int, T> $nodes */
        $nodes = [];

        foreach ($file->ast as $stmt) {
            foreach ($this->finder->findInstanceOf($stmt, $class) as $node) {
                $nodes[] = $node;
            }
        }

        return $nodes;
    }

    /**
     * Build a Finding, pulling a snippet from the file for the given lines.
     */
    protected function makeFinding(
        FileContext $file,
        string $severity,
        int $line,
        string $title,
        string $message,
        string $suggestion,
        int $snippetStart,
        int $snippetEnd,
    ): Finding {
        return new Finding(
            rule: $this->id(),
            code: RuleMeta::code($this->id()),
            category: RuleMeta::category($this->id()),
            severity: $severity,
            file: $file->path,
            line: $line,
            title: $title,
            message: $message,
            suggestion: $suggestion,
            snippet: $file->snippet($snippetStart, $snippetEnd),
        );
    }
}
