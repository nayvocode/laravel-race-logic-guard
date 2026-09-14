<?php

declare(strict_types=1);

namespace Nayvo\LaravelRaceGuard\Rules;

use Nayvo\LaravelRaceGuard\Analysis\FileContext;
use Nayvo\LaravelRaceGuard\Support\Ast;
use Nayvo\LaravelRaceGuard\Support\Severity;
use PhpParser\Node;

/**
 * Detects a queued job or listener that mutates data in handle() but has no
 * protection against overlapping / duplicate runs.
 *
 *   class ProcessPayout implements ShouldQueue
 *   {
 *       public function handle(): void
 *       {
 *           $wallet = Wallet::find($this->id);
 *           $wallet->decrement('balance', $this->amount);   // may run twice
 *       }
 *   }
 *
 * Queued jobs can be retried, released, or dispatched more than once, so a
 * mutating handle() with no ShouldBeUnique / WithoutOverlapping guard can run
 * concurrently and double-apply its effect.
 */
final class OverlappingJobRule extends AbstractRule
{
    /** @var array<int, string> Method calls that mutate persistent state. */
    private const MUTATORS = ['save', 'saveOrFail', 'update', 'decrement', 'increment', 'delete', 'create', 'insert'];

    public function id(): string
    {
        return 'overlapping_job';
    }

    public function analyze(FileContext $file): array
    {
        $findings = [];

        foreach ($this->findInstances($file, Node\Stmt\Class_::class) as $class) {
            if (! $this->isQueued($class) || $this->isProtected($class)) {
                continue;
            }

            $handle = $this->handleMethod($class);
            if ($handle === null || ! $this->mutatesState($handle)) {
                continue;
            }

            $line = $class->getStartLine();
            if (! $file->isInScope($line)) {
                continue;
            }

            $name = Ast::name($class->name) ?? 'This job';

            $findings[] = $this->makeFinding(
                file: $file,
                severity: Severity::MEDIUM,
                line: $line,
                title: 'Queued job mutates data without overlap protection.',
                message: "{$name} implements ShouldQueue and changes persistent state in handle(), "
                    .'but is not ShouldBeUnique and has no WithoutOverlapping middleware. If it is '
                    .'retried or dispatched more than once, two runs can overlap and double-apply '
                    .'the change.',
                suggestion: 'Implement ShouldBeUnique (with uniqueId()) so only one instance runs at '
                    .'a time, or add the WithoutOverlapping middleware in a middleware() method, '
                    .'and/or make the work idempotent.',
                snippetStart: $line,
                snippetEnd: $line,
            );
        }

        return $findings;
    }

    private function isQueued(Node\Stmt\Class_ $class): bool
    {
        foreach ($class->implements as $interface) {
            if (Ast::name($interface) === 'ShouldQueue') {
                return true;
            }
        }

        return false;
    }

    /**
     * Already guarded by ShouldBeUnique or a WithoutOverlapping middleware
     * anywhere in the class.
     */
    private function isProtected(Node\Stmt\Class_ $class): bool
    {
        foreach ($class->implements as $interface) {
            $name = Ast::name($interface);
            if ($name === 'ShouldBeUnique' || $name === 'ShouldBeUniqueUntilProcessing') {
                return true;
            }
        }

        // Any reference to WithoutOverlapping in the class body counts as a guard.
        $names = $this->finder->findInstanceOf($class, Node\Name::class);
        foreach ($names as $name) {
            if (str_contains($name->toString(), 'WithoutOverlapping')) {
                return true;
            }
        }

        return false;
    }

    private function handleMethod(Node\Stmt\Class_ $class): ?Node\Stmt\ClassMethod
    {
        foreach ($class->getMethods() as $method) {
            if (strtolower(Ast::name($method->name) ?? '') === 'handle') {
                return $method;
            }
        }

        return null;
    }

    private function mutatesState(Node\Stmt\ClassMethod $handle): bool
    {
        if ($handle->stmts === null) {
            return false;
        }

        return Ast::findCallsNamed($handle->stmts, self::MUTATORS) !== [];
    }
}
