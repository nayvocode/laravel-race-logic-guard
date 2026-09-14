<?php

declare(strict_types=1);

namespace Nayvo\LaravelRaceGuard\Reporting;

use Nayvo\LaravelRaceGuard\Analysis\Finding;
use Symfony\Component\Console\Output\OutputInterface;

interface Reporter
{
    /**
     * @param  array<int, Finding>  $findings
     * @param  int  $scannedFiles  Number of files that were analysed.
     */
    public function report(array $findings, int $scannedFiles, OutputInterface $output): void;
}
