<?php

declare(strict_types=1);

namespace Nayvo\LaravelRaceGuard\Rules;

use Nayvo\LaravelRaceGuard\Analysis\FileContext;
use Nayvo\LaravelRaceGuard\Analysis\Finding;
use Nayvo\LaravelRaceGuard\Support\Severity;

/**
 * Detects the classic read-modify-write race: a model attribute is read,
 * changed by a computed/variable amount, and written back with save().
 *
 *   $user->balance = $user->balance - $amount;
 *   $user->save();
 *
 * Two processes may read the same original value and overwrite each other.
 */
final class ReadModifyWriteRule extends MutationRule
{
    public function id(): string
    {
        return 'read_modify_write';
    }

    protected function wantsCounter(bool $isCounter): bool
    {
        // The counter rule owns constant increments; we take everything else.
        return $isCounter === false;
    }

    protected function buildFinding(FileContext $file, array $mutation, int $endLine): Finding
    {
        return $this->makeFinding(
            file: $file,
            severity: Severity::HIGH,
            line: $mutation['line'],
            title: 'Potential read-modify-write race condition.',
            message: "The value of \${$mutation['var']}->{$mutation['prop']} is read and "
                .'subsequently written without an atomic operation or database lock. '
                .'If two processes run this concurrently they may both read the same '
                .'original value and overwrite each other\'s change.',
            suggestion: 'Use an atomic database update, e.g. '
                ."Model::whereKey(\$id)->decrement('{$mutation['prop']}', \$amount), "
                .'or lock the row with lockForUpdate() inside a transaction before updating.',
            snippetStart: $mutation['line'],
            snippetEnd: $endLine,
        );
    }
}
