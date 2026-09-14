<?php

declare(strict_types=1);

namespace Nayvo\LaravelRaceGuard\Analysis;

/**
 * An immutable description of a single potential race condition.
 */
final class Finding
{
    /**
     * @param  string  $rule  Machine-readable rule id, e.g. "read_modify_write".
     * @param  string  $code  Stable RG code, e.g. "RG001".
     * @param  string  $category  Concurrency domain, one of Category::* .
     * @param  string  $severity  One of Severity::* .
     * @param  string  $file  Path to the file the finding was detected in.
     * @param  int  $line  1-based line number the finding anchors to.
     * @param  string  $title  Short human-readable title.
     * @param  string  $message  Explanation of why the pattern may be unsafe.
     * @param  string  $suggestion  A possible safer approach.
     * @param  string  $snippet  The offending source snippet.
     */
    public function __construct(
        public readonly string $rule,
        public readonly string $code,
        public readonly string $category,
        public readonly string $severity,
        public readonly string $file,
        public readonly int $line,
        public readonly string $title,
        public readonly string $message,
        public readonly string $suggestion,
        public readonly string $snippet = '',
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'rule' => $this->rule,
            'code' => $this->code,
            'category' => $this->category,
            'severity' => $this->severity,
            'file' => $this->file,
            'line' => $this->line,
            'title' => $this->title,
            'message' => $this->message,
            'suggestion' => $this->suggestion,
            'snippet' => $this->snippet,
        ];
    }
}
