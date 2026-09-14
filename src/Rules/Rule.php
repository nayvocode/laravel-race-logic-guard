<?php

declare(strict_types=1);

namespace Nayvo\LaravelRaceGuard\Rules;

use Nayvo\LaravelRaceGuard\Analysis\FileContext;
use Nayvo\LaravelRaceGuard\Analysis\Finding;

interface Rule
{
    /**
     * Stable machine-readable identifier, e.g. "read_modify_write".
     */
    public function id(): string;

    /**
     * Analyse a single file and return any findings.
     *
     * @return array<int, Finding>
     */
    public function analyze(FileContext $file): array;
}
