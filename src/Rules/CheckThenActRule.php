<?php

declare(strict_types=1);

namespace Nayvo\LaravelRaceGuard\Rules;

use Nayvo\LaravelRaceGuard\Analysis\FileContext;
use Nayvo\LaravelRaceGuard\Analysis\Finding;
use Nayvo\LaravelRaceGuard\Support\Ast;
use Nayvo\LaravelRaceGuard\Support\Severity;
use PhpParser\Node;

/**
 * Detects check-then-act races:
 *
 *   $order = Order::find($orderId);
 *   if ($order->status === 'pending') {
 *       $order->status = 'processing';
 *       $order->save();
 *   }
 *
 * Multiple workers can observe the same state before either changes it.
 */
final class CheckThenActRule extends AbstractRule
{
    /**
     * Reads that make a variable look like a freshly-fetched Eloquent model,
     * which is what makes an inline check-then-act meaningful.
     *
     * @var array<int, string>
     */
    private const READ_METHODS = [
        'find', 'findOrFail', 'findOrNew', 'first', 'firstOrFail',
        'firstWhere', 'sole', 'findMany',
    ];

    private const PERSIST_METHODS = ['save', 'saveOrFail', 'update', 'push', 'delete'];

    public function id(): string
    {
        return 'check_then_act';
    }

    public function analyze(FileContext $file): array
    {
        $findings = [];

        foreach ($this->findInstances($file, Node\Stmt\If_::class) as $if) {
            $finding = $this->analyzeIf($file, $if);

            if ($finding !== null) {
                $findings[] = $finding;
            }
        }

        return $findings;
    }

    private function analyzeIf(FileContext $file, Node\Stmt\If_ $if): ?Finding
    {
        if (! $file->isInScope($if->getStartLine())) {
            return null;
        }

        foreach ($this->conditionModelVariables($if->cond) as $var) {
            if (! $this->bodyMutatesAndPersists($if->stmts, $var)) {
                continue;
            }

            $scope = Ast::enclosingFunction($if) ?? $file->ast;

            // Confirm the variable is a fetched model and not some other object.
            if (! $this->variableIsModelRead($scope, $var)) {
                continue;
            }

            // Respect deliberate locks / transactions with row locks.
            if (Ast::isGuarded($if, $scope)) {
                continue;
            }

            $endLine = $this->blockEndLine($if);

            return $this->makeFinding(
                file: $file,
                severity: Severity::HIGH,
                line: $if->getStartLine(),
                title: 'Potential check-then-act race condition.',
                message: "The state of \${$var} is checked and then modified and saved. "
                    .'Another process may observe the same state before this block '
                    .'commits its change, so both may act on it.',
                suggestion: 'Load the row inside a transaction using lockForUpdate() '
                    .'before checking and updating it, so concurrent workers serialise '
                    .'on the locked row.',
                snippetStart: $if->getStartLine(),
                snippetEnd: $endLine,
            );
        }

        return null;
    }

    /**
     * Variable names whose property is read inside the condition, e.g. the
     * "order" in `$order->status === 'pending'`.
     *
     * @return array<int, string>
     */
    private function conditionModelVariables(Node\Expr $cond): array
    {
        $vars = [];

        $fetches = $this->finder->find($cond, static function (Node $node): bool {
            return ($node instanceof Node\Expr\PropertyFetch || $node instanceof Node\Expr\NullsafePropertyFetch)
                && $node->var instanceof Node\Expr\Variable
                && is_string($node->var->name)
                && $node->var->name !== 'this';
        });

        foreach ($fetches as $fetch) {
            if (($fetch instanceof Node\Expr\PropertyFetch || $fetch instanceof Node\Expr\NullsafePropertyFetch)
                && $fetch->var instanceof Node\Expr\Variable
                && is_string($fetch->var->name)) {
                $vars[$fetch->var->name] = $fetch->var->name;
            }
        }

        return array_values($vars);
    }

    /**
     * @param  array<int, Node\Stmt>  $stmts
     */
    private function bodyMutatesAndPersists(array $stmts, string $var): bool
    {
        return $this->bodyMutatesVariable($stmts, $var)
            && $this->bodyPersistsVariable($stmts, $var);
    }

    /**
     * @param  array<int, Node\Stmt>  $stmts
     */
    private function bodyMutatesVariable(array $stmts, string $var): bool
    {
        $mutations = $this->finder->find($stmts, function (Node $node) use ($var): bool {
            $target = null;

            if ($node instanceof Node\Expr\Assign || $node instanceof Node\Expr\AssignOp) {
                $target = $node->var;
            } elseif ($node instanceof Node\Expr\PostInc || $node instanceof Node\Expr\PreInc
                || $node instanceof Node\Expr\PostDec || $node instanceof Node\Expr\PreDec) {
                $target = $node->var;
            }

            if ($target === null) {
                return false;
            }

            return ($target instanceof Node\Expr\PropertyFetch || $target instanceof Node\Expr\NullsafePropertyFetch)
                && $target->var instanceof Node\Expr\Variable
                && $target->var->name === $var;
        });

        return $mutations !== [];
    }

    /**
     * @param  array<int, Node\Stmt>  $stmts
     */
    private function bodyPersistsVariable(array $stmts, string $var): bool
    {
        foreach (Ast::findCallsNamed($stmts, self::PERSIST_METHODS) as $call) {
            if (($call instanceof Node\Expr\MethodCall || $call instanceof Node\Expr\NullsafeMethodCall)
                && Ast::rootVariable($call) === $var) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  Node|array<int, Node>  $scope
     */
    private function variableIsModelRead(Node|array $scope, string $var): bool
    {
        $reads = $this->finder->find($scope, function (Node $node) use ($var): bool {
            if (! $node instanceof Node\Expr\Assign) {
                return false;
            }

            if (! $node->var instanceof Node\Expr\Variable || $node->var->name !== $var) {
                return false;
            }

            return Ast::findCallsNamed($node->expr, self::READ_METHODS) !== [];
        });

        return $reads !== [];
    }

    private function blockEndLine(Node\Stmt\If_ $if): int
    {
        $end = $if->getStartLine();

        foreach ($if->stmts as $stmt) {
            $end = max($end, $stmt->getEndLine());
        }

        return $end;
    }
}
