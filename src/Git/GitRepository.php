<?php

declare(strict_types=1);

namespace Nayvo\LaravelRaceGuard\Git;

use Symfony\Component\Process\Process;

/**
 * Thin, dependency-light wrapper around the Git CLI used to discover the
 * files (and lines) a developer is currently changing.
 */
class GitRepository
{
    public function __construct(
        protected string $basePath,
    ) {}

    /**
     * Is this directory inside a usable Git working tree?
     */
    public function isAvailable(): bool
    {
        $result = $this->run(['rev-parse', '--is-inside-work-tree']);

        return $result['ok'] && trim($result['output']) === 'true';
    }

    /**
     * Files that differ from HEAD in the working tree: staged, unstaged and
     * untracked. Paths are returned relative to the repository root.
     *
     * @return array<int, string>
     */
    public function dirtyFiles(): array
    {
        $result = $this->run(['status', '--porcelain', '--untracked-files=all']);

        if (! $result['ok']) {
            return [];
        }

        $files = [];

        foreach (preg_split('/\R/', $result['output']) ?: [] as $line) {
            if ($line === '') {
                continue;
            }

            // Porcelain format: XY <path> (or "XY orig -> path" for renames).
            $path = substr($line, 3);

            if (str_contains($path, ' -> ')) {
                $path = substr($path, strpos($path, ' -> ') + 4);
            }

            $files[] = $this->unquote(trim($path));
        }

        return array_values(array_unique($files));
    }

    /**
     * Only files staged for commit (the index).
     *
     * @return array<int, string>
     */
    public function stagedFiles(): array
    {
        $result = $this->run(['diff', '--cached', '--name-only', '--diff-filter=ACMR']);

        return $this->splitLines($result);
    }

    /**
     * Only unstaged working-tree changes (tracked files) plus untracked files.
     *
     * @return array<int, string>
     */
    public function unstagedFiles(): array
    {
        $tracked = $this->run(['diff', '--name-only', '--diff-filter=ACMR']);
        $untracked = $this->run(['ls-files', '--others', '--exclude-standard']);

        return array_values(array_unique(array_merge(
            $this->splitLines($tracked),
            $this->splitLines($untracked),
        )));
    }

    /**
     * The set of line numbers that were added or modified for a given file.
     *
     * Returns null when every line should be considered "changed" (for
     * example a brand new / untracked file), so callers can treat null as
     * "analyse the whole file".
     *
     * @return array<int, int>|null
     */
    public function changedLines(string $file): ?array
    {
        // Untracked files have no diff base — treat the whole file as new.
        if ($this->isUntracked($file)) {
            return null;
        }

        $lines = [];

        // Combine staged and unstaged hunks so we capture everything the
        // developer has touched, regardless of what is currently staged.
        foreach ([['diff', '--unified=0', '--', $file], ['diff', '--cached', '--unified=0', '--', $file]] as $args) {
            $result = $this->run($args);

            if (! $result['ok']) {
                continue;
            }

            foreach ($this->parseHunkLines($result['output']) as $number) {
                $lines[$number] = $number;
            }
        }

        return array_values($lines);
    }

    protected function isUntracked(string $file): bool
    {
        $result = $this->run(['ls-files', '--others', '--exclude-standard', '--', $file]);

        return $result['ok'] && trim($result['output']) !== '';
    }

    /**
     * Extract the 1-based line numbers of added lines from unified diff output.
     *
     * @return array<int, int>
     */
    protected function parseHunkLines(string $diff): array
    {
        $lines = [];
        $current = 0;
        $inHunk = false;

        foreach (preg_split('/\R/', $diff) ?: [] as $line) {
            if (str_starts_with($line, '@@')) {
                // @@ -a,b +c,d @@  — c is the new-file start line.
                if (preg_match('/\+(\d+)(?:,(\d+))?/', $line, $m)) {
                    $current = (int) $m[1];
                    $inHunk = true;
                }

                continue;
            }

            if (! $inHunk) {
                continue;
            }

            if (str_starts_with($line, '+')) {
                $lines[] = $current;
                $current++;
            } elseif (! str_starts_with($line, '-') && ! str_starts_with($line, '\\')) {
                $current++;
            }
        }

        return $lines;
    }

    /**
     * @param  array{ok: bool, output: string}  $result
     * @return array<int, string>
     */
    protected function splitLines(array $result): array
    {
        if (! $result['ok']) {
            return [];
        }

        $files = [];

        foreach (preg_split('/\R/', $result['output']) ?: [] as $line) {
            $line = trim($line);

            if ($line !== '') {
                $files[] = $this->unquote($line);
            }
        }

        return array_values(array_unique($files));
    }

    /**
     * Git quotes paths containing "unusual" characters; strip the quoting.
     */
    protected function unquote(string $path): string
    {
        if (strlen($path) >= 2 && $path[0] === '"' && $path[-1] === '"') {
            $decoded = stripcslashes(substr($path, 1, -1));

            return $decoded;
        }

        return $path;
    }

    /**
     * @param  array<int, string>  $args
     * @return array{ok: bool, output: string}
     */
    protected function run(array $args): array
    {
        $process = new Process(array_merge(['git'], $args), $this->basePath);
        $process->run();

        return [
            'ok' => $process->isSuccessful(),
            'output' => $process->getOutput(),
        ];
    }
}
