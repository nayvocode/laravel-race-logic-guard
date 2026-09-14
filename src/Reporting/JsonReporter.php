<?php

declare(strict_types=1);

namespace Nayvo\LaravelRaceGuard\Reporting;

use Nayvo\LaravelRaceGuard\Analysis\Finding;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Emits findings as machine-readable JSON for CI / code-review integration.
 */
final class JsonReporter implements Reporter
{
    public function report(array $findings, int $scannedFiles, OutputInterface $output): void
    {
        $payload = [
            'tool' => 'laravel-race-guard',
            'scanned_files' => $scannedFiles,
            'total' => count($findings),
            'findings' => array_map(
                static fn (Finding $finding): array => $finding->toArray(),
                $findings,
            ),
        ];

        $output->writeln((string) json_encode(
            $payload,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        ));
    }
}
