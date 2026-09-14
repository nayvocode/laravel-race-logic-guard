<?php

declare(strict_types=1);

namespace Nayvo\LaravelRaceGuard\Analysis;

use PhpParser\Node;

/**
 * Everything a rule needs to know about a single file under analysis.
 */
final class FileContext
{
    /** @var array<int, string> Source split into 1-based lines (index 0 unused). */
    private array $lines;

    /**
     * @param  string  $path  Display path (usually relative to the project root).
     * @param  string  $source  Raw file contents.
     * @param  array<int, Node>  $ast  Parsed statements with parent attributes attached.
     * @param  array<int, int>|null  $changedLines  Line numbers considered "changed", or null for the whole file.
     */
    public function __construct(
        public readonly string $path,
        public readonly string $source,
        public readonly array $ast,
        public readonly ?array $changedLines,
    ) {
        $split = preg_split('/\R/', $source) ?: [];
        // Make the array 1-based so $lines[$n] maps to line $n.
        array_unshift($split, '');
        $this->lines = $split;
    }

    /**
     * Should a finding anchored to $line be reported, given the changed-line
     * filter? When no filter is set (full-file scan / new file) everything is
     * in scope.
     */
    public function isInScope(int $line): bool
    {
        if ($this->changedLines === null) {
            return true;
        }

        return in_array($line, $this->changedLines, true);
    }

    /**
     * Extract a source snippet spanning the given (inclusive) line range,
     * trimmed of common leading indentation.
     */
    public function snippet(int $startLine, int $endLine): string
    {
        $startLine = max(1, $startLine);
        $endLine = min(count($this->lines) - 1, max($startLine, $endLine));

        $selected = [];
        for ($i = $startLine; $i <= $endLine; $i++) {
            $selected[] = $this->lines[$i] ?? '';
        }

        // Strip the smallest shared indentation for readability.
        $indent = null;
        foreach ($selected as $line) {
            if (trim($line) === '') {
                continue;
            }
            preg_match('/^\s*/', $line, $m);
            $len = strlen($m[0]);
            $indent = $indent === null ? $len : min($indent, $len);
        }

        $indent ??= 0;

        return implode("\n", array_map(
            static fn (string $line): string => substr($line, $indent),
            $selected,
        ));
    }
}
