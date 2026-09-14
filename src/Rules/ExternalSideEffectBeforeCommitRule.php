<?php

declare(strict_types=1);

namespace Nayvo\LaravelRaceGuard\Rules;

use Nayvo\LaravelRaceGuard\Analysis\FileContext;
use Nayvo\LaravelRaceGuard\Support\Ast;
use Nayvo\LaravelRaceGuard\Support\Severity;
use PhpParser\Node;

/**
 * Detects an external, irreversible side effect performed inside a
 * DB::transaction() before the transaction commits:
 *
 *   DB::transaction(function () use ($order) {
 *       $order->update(['status' => 'paid']);
 *       Http::post('https://gateway/charge', [...]);   // fires before commit
 *       Mail::to($order->email)->send(new Receipt($order));
 *   });
 *
 * If the transaction rolls back after the HTTP call or mail send, the external
 * effect has already happened and cannot be undone; and a queued job dispatched
 * here can start before the row it needs is committed. Such effects belong
 * after commit (afterCommit / DB::afterCommit / dispatch()->afterCommit()).
 */
final class ExternalSideEffectBeforeCommitRule extends AbstractRule
{
    /** @var array<int, string> Facade calls that reach outside the DB. */
    private const EXTERNAL_STATIC = ['Http', 'Mail', 'Notification', 'Bus'];

    /** @var array<int, string> Global helper functions with external effects. */
    private const EXTERNAL_FUNCTIONS = ['dispatch', 'event', 'broadcast'];

    public function id(): string
    {
        return 'external_side_effect_before_commit';
    }

    public function analyze(FileContext $file): array
    {
        $findings = [];
        $seen = [];

        foreach ($this->transactionClosures($file) as $closure) {
            foreach ($this->externalCalls($closure) as $call) {
                $line = $call->getStartLine();

                if (isset($seen[$line]) || ! $file->isInScope($line)) {
                    continue;
                }
                $seen[$line] = true;

                $findings[] = $this->makeFinding(
                    file: $file,
                    severity: Severity::MEDIUM,
                    line: $line,
                    title: 'External side effect inside a transaction (runs before commit).',
                    message: 'This external side effect (HTTP call, mail, notification, event or '
                        .'job dispatch) runs inside DB::transaction() before it commits. If the '
                        .'transaction rolls back the effect cannot be undone, and a dispatched job '
                        .'may run before its data is committed.',
                    suggestion: 'Move the effect to after the transaction commits — use '
                        .'DB::afterCommit()/the afterCommit hook, dispatch()->afterCommit(), or run '
                        .'it after the transaction closure returns.',
                    snippetStart: $line,
                    snippetEnd: $line,
                );
            }
        }

        return $findings;
    }

    /**
     * @return array<int, Node\Expr\Closure|Node\Expr\ArrowFunction>
     */
    private function transactionClosures(FileContext $file): array
    {
        $closures = [];

        foreach ($this->findInstances($file, Node\Expr\StaticCall::class) as $call) {
            if (Ast::name($call->class) !== 'DB' || Ast::name($call->name) !== 'transaction') {
                continue;
            }

            foreach ($call->args as $arg) {
                if ($arg instanceof Node\Arg
                    && ($arg->value instanceof Node\Expr\Closure || $arg->value instanceof Node\Expr\ArrowFunction)) {
                    $closures[] = $arg->value;
                }
            }
        }

        return $closures;
    }

    /**
     * @return array<int, Node>
     */
    private function externalCalls(Node\FunctionLike $closure): array
    {
        return $this->finder->find($closure, function (Node $node): bool {
            if ($node instanceof Node\Expr\StaticCall) {
                // Skip a dispatch already deferred to after commit.
                return in_array(Ast::name($node->class), self::EXTERNAL_STATIC, true);
            }

            if ($node instanceof Node\Expr\FuncCall && $node->name instanceof Node\Name) {
                return in_array($node->name->toString(), self::EXTERNAL_FUNCTIONS, true)
                    && ! $this->isDeferredDispatch($node);
            }

            return false;
        });
    }

    /**
     * `dispatch(...)->afterCommit()` is already safe.
     */
    private function isDeferredDispatch(Node\Expr\FuncCall $call): bool
    {
        $parent = $call->getAttribute('parent');

        while ($parent instanceof Node) {
            if ($parent instanceof Node\Expr\MethodCall && Ast::name($parent->name) === 'afterCommit') {
                return true;
            }

            $parent = $parent->getAttribute('parent');
        }

        return false;
    }
}
