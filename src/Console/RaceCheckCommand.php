<?php

declare(strict_types=1);

namespace Nayvo\LaravelRaceGuard\Console;

use Illuminate\Console\Command;
use Nayvo\LaravelRaceGuard\Analysis\Analyzer;
use Nayvo\LaravelRaceGuard\Analysis\Finding;
use Nayvo\LaravelRaceGuard\Git\GitRepository;
use Nayvo\LaravelRaceGuard\Reporting\ConsoleReporter;
use Nayvo\LaravelRaceGuard\Reporting\JsonReporter;
use Nayvo\LaravelRaceGuard\Rules\RuleFactory;
use Nayvo\LaravelRaceGuard\Support\Severity;

class RaceCheckCommand extends Command
{
    protected $signature = 'race:check
        {--path=* : Analyse these files or directories instead of Git changes}
        {--all : Scan the configured paths instead of only Git changes}
        {--staged : Only inspect files staged for commit}
        {--unstaged : Only inspect unstaged / untracked changes}
        {--whole-file : Scan the whole of each changed file (not only changed lines)}
        {--changed-lines : Only report findings that fall on changed lines}
        {--format=console : Output format: console or json}
        {--severity= : Minimum severity to report (low, medium, high, critical)}
        {--category=* : Only report findings in these categories (e.g. payments, queue, cache)}';

    protected $description = 'Detect potential race conditions in changed Laravel code.';

    public function handle(): int
    {
        /** @var array<string, mixed> $config */
        $config = (array) $this->laravel['config']->get('race-guard', []);

        $minimumSeverity = $this->resolveMinimumSeverity($config);
        $rules = RuleFactory::make((array) ($config['rules'] ?? []));
        $analyzer = new Analyzer($rules, $minimumSeverity);

        [$targets, $scanningAll] = $this->collectTargets($config);

        $findings = [];
        $scanned = 0;

        foreach ($targets as $target) {
            $scanned++;
            $changedLines = $scanningAll ? null : $target['changedLines'];

            foreach ($analyzer->analyzeFile($target['absolute'], $target['display'], $changedLines) as $finding) {
                $findings[] = $finding;
            }
        }

        $findings = $this->filterByCategory($this->sortFindings($findings));

        $format = (string) $this->option('format');
        $reporter = $format === 'json' ? new JsonReporter : new ConsoleReporter;
        $reporter->report($findings, $scanned, $this->output->getOutput());

        return $this->exitCode($findings, $config);
    }

    /**
     * @param  array<string, mixed>  $config
     * @return array{0: array<int, array{absolute: string, display: string, changedLines: array<int, int>|null}>, 1: bool}
     */
    private function collectTargets(array $config): array
    {
        $excludes = (array) ($config['exclude'] ?? []);

        // Explicit paths win and are always fully scanned.
        $paths = (array) $this->option('path');
        if ($paths !== []) {
            return [$this->expandPaths($paths, $excludes), true];
        }

        // Full-project scan of configured paths.
        if ($this->option('all')) {
            $roots = array_map(
                fn (string $p): string => $this->basePath($p),
                (array) ($config['paths'] ?? []),
            );

            return [$this->expandPaths($roots, $excludes), true];
        }

        // Default: Git-aware scan of the files being changed.
        return [$this->collectGitTargets($config, $excludes), false];
    }

    /**
     * @param  array<string, mixed>  $config
     * @param  array<int, string>  $excludes
     * @return array<int, array{absolute: string, display: string, changedLines: array<int, int>|null}>
     */
    private function collectGitTargets(array $config, array $excludes): array
    {
        $git = new GitRepository($this->basePath());

        if (! $git->isAvailable()) {
            $this->warn('Not a Git repository — nothing to scan. Use --all or --path to scan explicitly.');

            return [];
        }

        $scope = $this->resolveScope((string) ($config['git_scope'] ?? 'dirty'));

        $files = match ($scope) {
            'staged' => $git->stagedFiles(),
            'unstaged' => $git->unstagedFiles(),
            default => $git->dirtyFiles(),
        };

        $wholeFile = $this->scanWholeFile($config);
        $targets = [];

        foreach ($files as $relative) {
            if (! str_ends_with($relative, '.php') || $this->isExcluded($relative, $excludes)) {
                continue;
            }

            $absolute = $this->basePath($relative);
            if (! is_file($absolute)) {
                continue;
            }

            $targets[] = [
                'absolute' => $absolute,
                'display' => $relative,
                // Whole-file mode analyses every line of a changed file; the
                // changed-lines mode restricts findings to the diff hunks.
                'changedLines' => $wholeFile ? null : $git->changedLines($relative),
            ];
        }

        return $targets;
    }

    /**
     * Whether a changed file should be analysed in full (default) or narrowed
     * to just the changed lines. CLI flags win over the config default.
     *
     * @param  array<string, mixed>  $config
     */
    private function scanWholeFile(array $config): bool
    {
        if ($this->option('whole-file')) {
            return true;
        }

        if ($this->option('changed-lines')) {
            return false;
        }

        // Config default: 'file' scans the whole changed file, 'lines' narrows
        // to changed lines. Defaults to whole-file.
        return (string) ($config['diff_granularity'] ?? 'file') !== 'lines';
    }

    /**
     * Expand a mix of files and directories into a flat list of PHP targets.
     *
     * @param  array<int, string>  $paths
     * @param  array<int, string>  $excludes
     * @return array<int, array{absolute: string, display: string, changedLines: null}>
     */
    private function expandPaths(array $paths, array $excludes): array
    {
        $targets = [];

        foreach ($paths as $path) {
            $absolute = $this->toAbsolute($path);

            if (is_file($absolute)) {
                if (str_ends_with($absolute, '.php') && ! $this->isExcluded($absolute, $excludes)) {
                    $targets[$absolute] = $this->makeExplicitTarget($absolute);
                }

                continue;
            }

            if (is_dir($absolute)) {
                foreach ($this->phpFilesIn($absolute) as $file) {
                    if (! $this->isExcluded($file, $excludes)) {
                        $targets[$file] = $this->makeExplicitTarget($file);
                    }
                }
            }
        }

        return array_values($targets);
    }

    /**
     * @return array{absolute: string, display: string, changedLines: null}
     */
    private function makeExplicitTarget(string $absolute): array
    {
        return [
            'absolute' => $absolute,
            'display' => $this->relativeToBase($absolute),
            'changedLines' => null,
        ];
    }

    /**
     * @return array<int, string>
     */
    private function phpFilesIn(string $directory): array
    {
        $files = [];

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS),
        );

        foreach ($iterator as $file) {
            if ($file instanceof \SplFileInfo && $file->isFile() && $file->getExtension() === 'php') {
                $files[] = $file->getPathname();
            }
        }

        sort($files);

        return $files;
    }

    /**
     * @param  array<int, string>  $excludes
     */
    private function isExcluded(string $path, array $excludes): bool
    {
        $normalized = str_replace('\\', '/', $path);

        foreach ($excludes as $fragment) {
            $fragment = str_replace('\\', '/', (string) $fragment);

            if ($fragment !== '' && str_contains($normalized, $fragment)) {
                return true;
            }
        }

        return false;
    }

    private function resolveScope(string $configured): string
    {
        if ($this->option('staged')) {
            return 'staged';
        }

        if ($this->option('unstaged')) {
            return 'unstaged';
        }

        return in_array($configured, ['staged', 'unstaged', 'dirty'], true) ? $configured : 'dirty';
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private function resolveMinimumSeverity(array $config): string
    {
        $severity = (string) ($this->option('severity') ?: ($config['minimum_severity'] ?? Severity::LOW));

        return Severity::isValid($severity) ? $severity : Severity::LOW;
    }

    /**
     * @param  array<int, Finding>  $findings
     * @param  array<string, mixed>  $config
     */
    private function exitCode(array $findings, array $config): int
    {
        $failOn = $config['fail_on'] ?? null;

        if (! is_string($failOn) || ! Severity::isValid($failOn)) {
            return self::SUCCESS;
        }

        foreach ($findings as $finding) {
            if (Severity::atLeast($finding->severity, $failOn)) {
                return self::FAILURE;
            }
        }

        return self::SUCCESS;
    }

    /**
     * @param  array<int, Finding>  $findings
     * @return array<int, Finding>
     */
    private function sortFindings(array $findings): array
    {
        usort($findings, static function ($a, $b): int {
            return Severity::weight($b->severity) <=> Severity::weight($a->severity)
                ?: [$a->file, $a->line] <=> [$b->file, $b->line];
        });

        return $findings;
    }

    /**
     * Restrict findings to the categories passed via --category (if any).
     *
     * @param  array<int, Finding>  $findings
     * @return array<int, Finding>
     */
    private function filterByCategory(array $findings): array
    {
        $categories = array_map('strtolower', (array) $this->option('category'));

        if ($categories === []) {
            return $findings;
        }

        return array_values(array_filter(
            $findings,
            static fn ($finding): bool => in_array($finding->category, $categories, true),
        ));
    }

    private function basePath(string $path = ''): string
    {
        return $this->laravel->basePath($path);
    }

    private function toAbsolute(string $path): string
    {
        if ($this->isAbsolutePath($path)) {
            return $path;
        }

        return $this->basePath($path);
    }

    private function isAbsolutePath(string $path): bool
    {
        return str_starts_with($path, '/') || (bool) preg_match('#^[A-Za-z]:[\\\\/]#', $path);
    }

    private function relativeToBase(string $absolute): string
    {
        $base = rtrim(str_replace('\\', '/', $this->basePath()), '/').'/';
        $normalized = str_replace('\\', '/', $absolute);

        if (str_starts_with($normalized, $base)) {
            return substr($normalized, strlen($base));
        }

        return $absolute;
    }
}
