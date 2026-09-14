<?php

declare(strict_types=1);

namespace Nayvo\LaravelRaceGuard\Reporting;

use Nayvo\LaravelRaceGuard\Analysis\Finding;
use Nayvo\LaravelRaceGuard\Support\Category;
use Nayvo\LaravelRaceGuard\Support\Severity;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Renders findings as a human-readable report, in the style shown in the
 * package README.
 */
final class ConsoleReporter implements Reporter
{
    private const RULE = '────────────────────────────────────────';

    /**
     * @var array<string, string> Console colour per severity.
     */
    private const COLORS = [
        Severity::CRITICAL => 'red',
        Severity::HIGH => 'red',
        Severity::MEDIUM => 'yellow',
        Severity::LOW => 'cyan',
    ];

    public function report(array $findings, int $scannedFiles, OutputInterface $output): void
    {
        $output->writeln('');
        $output->writeln('<options=bold>Laravel RaceGuard</>');
        $output->writeln(self::RULE);
        $output->writeln('');

        if ($scannedFiles === 0) {
            $output->writeln('No changed PHP files to scan.');
            $output->writeln('');

            return;
        }

        $output->writeln(sprintf(
            'Scanned %d changed PHP %s...',
            $scannedFiles,
            $scannedFiles === 1 ? 'file' : 'files',
        ));
        $output->writeln('');

        if ($findings === []) {
            $output->writeln('<info>No potential race conditions found.</info>');
            $output->writeln('');

            return;
        }

        foreach ($this->groupByCategory($findings) as $category => $group) {
            $output->writeln(sprintf('<options=bold;fg=blue>%s</>', strtoupper(Category::label($category))));
            $output->writeln('');

            foreach ($group as $finding) {
                $this->renderFinding($finding, $output);
            }
        }

        $output->writeln(self::RULE);
        $output->writeln('');
        $count = count($findings);
        $output->writeln(sprintf(
            '<options=bold>%d potential race %s found.</>',
            $count,
            $count === 1 ? 'condition' : 'conditions',
        ));
        $output->writeln('');
    }

    private function renderFinding(Finding $finding, OutputInterface $output): void
    {
        $color = self::COLORS[$finding->severity] ?? 'white';

        $output->writeln(sprintf(
            '<fg=%s;options=bold>%s  %s</>',
            $color,
            $finding->code,
            Severity::label($finding->severity),
        ));
        $output->writeln(sprintf('<options=bold>%s:%d</>', $finding->file, $finding->line));
        $output->writeln('');
        $output->writeln($this->wrap($finding->title));
        $output->writeln('');

        if ($finding->snippet !== '') {
            foreach (explode("\n", $finding->snippet) as $line) {
                $output->writeln('    <fg=gray>'.$this->escape($line).'</>');
            }
            $output->writeln('');
        }

        $output->writeln($this->wrap($finding->message));
        $output->writeln('');
        $output->writeln('<options=bold>Suggestion:</>');
        $output->writeln($this->wrap($finding->suggestion));
        $output->writeln('');
    }

    /**
     * Group findings by category, ordered by Category::all(), preserving the
     * incoming severity order within each group.
     *
     * @param  array<int, Finding>  $findings
     * @return array<string, array<int, Finding>>
     */
    private function groupByCategory(array $findings): array
    {
        $groups = [];
        foreach ($findings as $finding) {
            $groups[$finding->category][] = $finding;
        }

        $ordered = [];
        foreach (Category::all() as $category) {
            if (isset($groups[$category])) {
                $ordered[$category] = $groups[$category];
            }
        }

        // Any category not in the canonical list still gets shown.
        foreach ($groups as $category => $group) {
            if (! isset($ordered[$category])) {
                $ordered[$category] = $group;
            }
        }

        return $ordered;
    }

    private function escape(string $text): string
    {
        return str_replace(['<', '>'], ['\\<', '\\>'], $text);
    }

    private function wrap(string $text, int $width = 66): string
    {
        return wordwrap($text, $width, "\n", false);
    }
}
