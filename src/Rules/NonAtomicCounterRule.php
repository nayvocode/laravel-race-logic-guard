<?php

declare(strict_types=1);

namespace Nayvo\LaravelRaceGuard\Rules;

use Nayvo\LaravelRaceGuard\Analysis\FileContext;
use Nayvo\LaravelRaceGuard\Analysis\Finding;
use Nayvo\LaravelRaceGuard\Support\Severity;

/**
 * Detects non-atomic counter increments:
 *
 *   $post->views = $post->views + 1;
 *   $post->save();
 *
 * Concurrent increments can be lost. The safe form is $post->increment('views').
 */
final class NonAtomicCounterRule extends MutationRule
{
    public function id(): string
    {
        return 'non_atomic_counter';
    }

    protected function wantsCounter(bool $isCounter): bool
    {
        return $isCounter === true;
    }

    protected function buildFinding(FileContext $file, array $mutation, int $endLine): Finding
    {
        return $this->makeFinding(
            file: $file,
            severity: Severity::MEDIUM,
            line: $mutation['line'],
            title: 'Non-atomic counter update.',
            message: "The counter \${$mutation['var']}->{$mutation['prop']} is incremented in "
                .'PHP and then saved. Concurrent requests can read the same starting '
                .'value, so increments may be lost.',
            suggestion: 'Use an atomic counter operation instead, e.g. '
                ."\${$mutation['var']}->increment('{$mutation['prop']}') "
                ."or \${$mutation['var']}->decrement('{$mutation['prop']}').",
            snippetStart: $mutation['line'],
            snippetEnd: $endLine,
        );
    }
}
