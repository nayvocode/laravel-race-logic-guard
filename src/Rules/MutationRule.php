<?php

declare(strict_types=1);

namespace Nayvo\LaravelRaceGuard\Rules;

use Nayvo\LaravelRaceGuard\Analysis\FileContext;
use Nayvo\LaravelRaceGuard\Analysis\Finding;
use Nayvo\LaravelRaceGuard\Support\Ast;
use PhpParser\Node;

/**
 * Shared machinery for rules that detect an in-memory "read the property,
 * change it, write it back" mutation on an Eloquent model that is then
 * persisted with save()/update(). Subclasses decide whether they care about
 * the constant-increment (counter) variant or the general variant.
 */
abstract class MutationRule extends AbstractRule
{
    /**
     * Methods that persist a model's current in-memory state.
     *
     * @var array<int, string>
     */
    protected const PERSIST_METHODS = ['save', 'saveOrFail', 'update', 'push'];

    /**
     * Should this rule report a mutation with the given "counter" shape?
     * A counter mutation increments/decrements a property by an integer
     * literal (e.g. `+ 1`); a non-counter mutation changes it by a computed
     * or variable amount (e.g. `- $amount`).
     */
    abstract protected function wantsCounter(bool $isCounter): bool;

    public function analyze(FileContext $file): array
    {
        $findings = [];

        foreach ($this->findMutations($file) as $mutation) {
            if (! $this->wantsCounter($mutation['isCounter'])) {
                continue;
            }

            if (! $file->isInScope($mutation['line'])) {
                continue;
            }

            $scope = $mutation['scope'];

            // Must actually be persisted, otherwise it's just a local calc.
            $saveLine = $this->persistLineForVariable($scope, $mutation['var']);
            if ($saveLine === null) {
                continue;
            }

            // Respect deliberate locking / atomic guards in the same scope.
            if (Ast::isGuarded($mutation['node'], $scope)) {
                continue;
            }

            $endLine = ($saveLine >= $mutation['line'] && $saveLine - $mutation['line'] <= 4)
                ? $saveLine
                : $mutation['line'];

            $findings[] = $this->buildFinding($file, $mutation, $endLine);
        }

        return $findings;
    }

    /**
     * Locate every property mutation in the file.
     *
     * @return array<int, array{var: string, prop: string, line: int, isCounter: bool, scope: Node|array<int, Node>, node: Node}>
     */
    protected function findMutations(FileContext $file): array
    {
        $mutations = [];

        foreach ($this->findInstances($file, Node\Expr::class) as $node) {
            $described = $this->describeMutation($node);

            if ($described === null) {
                continue;
            }

            $described['scope'] = Ast::enclosingFunction($node) ?? $file->ast;
            $described['node'] = $node;
            $mutations[] = $described;
        }

        return $mutations;
    }

    /**
     * @return array{var: string, prop: string, line: int, isCounter: bool}|null
     */
    protected function describeMutation(Node\Expr $node): ?array
    {
        // $var->prop = $var->prop <op> <delta>
        if ($node instanceof Node\Expr\Assign) {
            return $this->describeAssign($node);
        }

        // $var->prop += <delta> / -= / .= / *= ...
        if ($node instanceof Node\Expr\AssignOp) {
            return $this->describeAssignOp($node);
        }

        // $var->prop++ / --$var->prop
        if ($node instanceof Node\Expr\PostInc
            || $node instanceof Node\Expr\PreInc
            || $node instanceof Node\Expr\PostDec
            || $node instanceof Node\Expr\PreDec) {
            return $this->describeIncDec($node);
        }

        return null;
    }

    /**
     * @return array{var: string, prop: string, line: int, isCounter: bool}|null
     */
    private function describeAssign(Node\Expr\Assign $node): ?array
    {
        $target = $this->propertyTarget($node->var);
        if ($target === null) {
            return null;
        }

        // The right-hand side must read the same property back.
        if (! $this->expressionReadsProperty($node->expr, $target['var'], $target['prop'])) {
            return null;
        }

        return [
            'var' => $target['var'],
            'prop' => $target['prop'],
            'line' => $node->getStartLine(),
            'isCounter' => $this->isConstantDelta($node->expr, $target['var'], $target['prop']),
        ];
    }

    /**
     * @return array{var: string, prop: string, line: int, isCounter: bool}|null
     */
    private function describeAssignOp(Node\Expr\AssignOp $node): ?array
    {
        $target = $this->propertyTarget($node->var);
        if ($target === null) {
            return null;
        }

        $isPlusMinus = $node instanceof Node\Expr\AssignOp\Plus
            || $node instanceof Node\Expr\AssignOp\Minus;

        return [
            'var' => $target['var'],
            'prop' => $target['prop'],
            'line' => $node->getStartLine(),
            'isCounter' => $isPlusMinus && $this->isIntLiteral($node->expr),
        ];
    }

    /**
     * @return array{var: string, prop: string, line: int, isCounter: bool}|null
     */
    private function describeIncDec(Node\Expr $node): ?array
    {
        /** @var Node\Expr\PostInc|Node\Expr\PreInc|Node\Expr\PostDec|Node\Expr\PreDec $node */
        $target = $this->propertyTarget($node->var);
        if ($target === null) {
            return null;
        }

        return [
            'var' => $target['var'],
            'prop' => $target['prop'],
            'line' => $node->getStartLine(),
            'isCounter' => true,
        ];
    }

    /**
     * When $expr is `$var->prop` on a simple variable, return its parts.
     *
     * @return array{var: string, prop: string}|null
     */
    private function propertyTarget(Node\Expr $expr): ?array
    {
        if (! $expr instanceof Node\Expr\PropertyFetch && ! $expr instanceof Node\Expr\NullsafePropertyFetch) {
            return null;
        }

        if (! $expr->var instanceof Node\Expr\Variable || ! is_string($expr->var->name)) {
            return null;
        }

        $prop = Ast::name($expr->name);
        if ($prop === null) {
            return null;
        }

        return ['var' => $expr->var->name, 'prop' => $prop];
    }

    /**
     * Does $expr read `$var->prop` anywhere inside it?
     */
    private function expressionReadsProperty(Node $expr, string $var, string $prop): bool
    {
        $matches = $this->finder->find($expr, function (Node $node) use ($var, $prop): bool {
            if (! $node instanceof Node\Expr\PropertyFetch && ! $node instanceof Node\Expr\NullsafePropertyFetch) {
                return false;
            }

            return $node->var instanceof Node\Expr\Variable
                && $node->var->name === $var
                && Ast::name($node->name) === $prop;
        });

        return $matches !== [];
    }

    /**
     * Is the mutation a plain constant increment/decrement of the property,
     * i.e. `$var->prop + <int>` or `<int> + $var->prop`?
     */
    private function isConstantDelta(Node\Expr $expr, string $var, string $prop): bool
    {
        if (! $expr instanceof Node\Expr\BinaryOp\Plus && ! $expr instanceof Node\Expr\BinaryOp\Minus) {
            return false;
        }

        $left = $expr->left;
        $right = $expr->right;

        $leftIsProp = $this->isPropertyFetch($left, $var, $prop);
        $rightIsProp = $this->isPropertyFetch($right, $var, $prop);

        if ($leftIsProp && $this->isIntLiteral($right)) {
            return true;
        }

        // Minus is not commutative, so only `prop - int` counts here.
        if ($rightIsProp && $this->isIntLiteral($left) && $expr instanceof Node\Expr\BinaryOp\Plus) {
            return true;
        }

        return false;
    }

    private function isPropertyFetch(Node $node, string $var, string $prop): bool
    {
        return ($node instanceof Node\Expr\PropertyFetch || $node instanceof Node\Expr\NullsafePropertyFetch)
            && $node->var instanceof Node\Expr\Variable
            && $node->var->name === $var
            && Ast::name($node->name) === $prop;
    }

    private function isIntLiteral(Node $node): bool
    {
        return $node instanceof Node\Scalar\Int_
            || $node instanceof Node\Scalar\Float_;
    }

    /**
     * Find the line of a persist call (`$var->save()` etc.) on the same
     * variable within the scope, or null if the value is never written back.
     *
     * @param  Node|array<int, Node>  $scope
     */
    private function persistLineForVariable(Node|array $scope, string $var): ?int
    {
        foreach (Ast::findCallsNamed($scope, self::PERSIST_METHODS) as $call) {
            if (! $call instanceof Node\Expr\MethodCall && ! $call instanceof Node\Expr\NullsafeMethodCall) {
                continue;
            }

            if (Ast::rootVariable($call) === $var) {
                return $call->getStartLine();
            }
        }

        return null;
    }

    /**
     * @param  array{var: string, prop: string, line: int, isCounter: bool, scope: Node|array<int, Node>, node: Node}  $mutation
     */
    abstract protected function buildFinding(FileContext $file, array $mutation, int $endLine): Finding;
}
