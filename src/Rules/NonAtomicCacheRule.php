<?php

declare(strict_types=1);

namespace Nayvo\LaravelRaceGuard\Rules;

use Nayvo\LaravelRaceGuard\Analysis\FileContext;
use Nayvo\LaravelRaceGuard\Support\Ast;
use Nayvo\LaravelRaceGuard\Support\Severity;
use PhpParser\Node;

/**
 * Detects a non-atomic cache counter: reading a value with Cache::get() and
 * writing back an arithmetic result with Cache::put().
 *
 *   $count = Cache::get($key, 0);
 *   Cache::put($key, $count + 1);   // lost updates under concurrency
 *
 * Two requests can read the same value and both write back the same +1. The
 * atomic replacements are Cache::increment()/decrement(), or a Cache::lock()
 * around the read-modify-write.
 */
final class NonAtomicCacheRule extends AbstractRule
{
    public function id(): string
    {
        return 'non_atomic_cache';
    }

    public function analyze(FileContext $file): array
    {
        $findings = [];

        foreach ($this->findInstances($file, Node\Expr\StaticCall::class) as $call) {
            if (Ast::name($call->class) !== 'Cache' || Ast::name($call->name) !== 'put') {
                continue;
            }

            // Second argument must be an add/subtract expression.
            $value = $call->args[1] ?? null;
            if (! $value instanceof Node\Arg || ! $this->isArithmetic($value->value)) {
                continue;
            }

            $scope = Ast::enclosingFunction($call) ?? $file->ast;

            // Only when there is a matching Cache::get in the same scope — that
            // is what makes it a read-modify-write rather than a plain write.
            if (! $this->scopeReadsCache($scope)) {
                continue;
            }

            // A Cache::lock() guard — in scope or wrapping the callback — is safe.
            if (Ast::isGuarded($call, $scope)) {
                continue;
            }

            $line = $call->getStartLine();
            if (! $file->isInScope($line)) {
                continue;
            }

            $findings[] = $this->makeFinding(
                file: $file,
                severity: Severity::MEDIUM,
                line: $line,
                title: 'Non-atomic cache read-modify-write.',
                message: 'A cache value is read with Cache::get() and written back with an '
                    .'arithmetic Cache::put(). Concurrent requests can read the same value and '
                    .'lose each other\'s update.',
                suggestion: 'Use the atomic Cache::increment()/Cache::decrement() helpers, or wrap '
                    .'the read-modify-write in Cache::lock(...).',
                snippetStart: $line,
                snippetEnd: $line,
            );
        }

        return $findings;
    }

    private function isArithmetic(Node\Expr $expr): bool
    {
        return $expr instanceof Node\Expr\BinaryOp\Plus
            || $expr instanceof Node\Expr\BinaryOp\Minus
            || $expr instanceof Node\Expr\BinaryOp\Mul;
    }

    /**
     * @param  Node|array<int, Node>  $scope
     */
    private function scopeReadsCache(Node|array $scope): bool
    {
        foreach ($this->finder->findInstanceOf($scope, Node\Expr\StaticCall::class) as $call) {
            if (Ast::name($call->class) === 'Cache' && Ast::name($call->name) === 'get') {
                return true;
            }
        }

        return false;
    }
}
