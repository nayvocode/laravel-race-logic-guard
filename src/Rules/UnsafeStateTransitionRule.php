<?php

declare(strict_types=1);

namespace Nayvo\LaravelRaceGuard\Rules;

use Nayvo\LaravelRaceGuard\Analysis\FileContext;
use Nayvo\LaravelRaceGuard\Support\Ast;
use Nayvo\LaravelRaceGuard\Support\Severity;
use PhpParser\Node;

/**
 * Detects an unguarded status/state transition: a model or query-builder update that
 * sets a `status`/`state` column to a fixed value, with no condition on the
 * current value, after that same record has read the field.
 *
 *   $order = Order::find($id);
 *   if ($order->status !== 'pending') {
 *       abort(409);
 *   }
 *   Order::whereKey($id)->update(['status' => 'processing']);  // first-wins?
 *
 * Two workers can both read 'pending' and both run the update, so both proceed.
 * A first-wins transition must be atomic:
 * `->where('status', 'pending')->update(['status' => 'processing'])` and act
 * only when one row was affected.
 */
final class UnsafeStateTransitionRule extends AbstractRule
{
    /** @var array<int, string> */
    private const STATE_FIELDS = ['status', 'state'];

    /** @var array<int, string> */
    private const KEY_FIELDS = ['id', '_id'];

    /** @var array<int, string> */
    private const KEY_READS = ['find', 'findOrFail'];

    public function id(): string
    {
        return 'unsafe_state_transition';
    }

    public function analyze(FileContext $file): array
    {
        $findings = [];
        $seen = [];

        foreach ($this->findInstances($file, Node\Expr\MethodCall::class) as $call) {
            if (Ast::name($call->name) !== 'update') {
                continue;
            }

            $field = $this->stateFieldSetBy($call);
            if ($field === null) {
                continue;
            }

            // A condition on the same field in the chain makes it atomic.
            if ($this->chainConditionsOn($call, $field)) {
                continue;
            }

            $scope = Ast::enclosingFunction($call) ?? $file->ast;

            $target = $this->updateTarget($call);
            if ($target === null) {
                // A query-builder update without an instance or a key-based
                // selector cannot be tied to a prior read safely.
                continue;
            }

            // Only when the scope previously reads/checks that state on the
            // same record — the check-then-act intent.
            if (! $this->scopeChecksField($scope, $field, $call, $target)) {
                continue;
            }

            if (Ast::isGuarded($call, $scope)) {
                continue;
            }

            $line = $call->getStartLine();
            if (isset($seen[$line]) || ! $file->isInScope($line)) {
                continue;
            }
            $seen[$line] = true;

            $findings[] = $this->makeFinding(
                file: $file,
                severity: Severity::HIGH,
                line: $line,
                title: 'Unguarded status transition (check-then-act).',
                message: "The '{$field}' column is checked and then set to a fixed value with an "
                    .'unconditional update. Two requests can both observe the same starting state '
                    .'and both perform the transition, so both proceed as if they won.',
                suggestion: 'Make the transition atomic and first-wins: '
                    ."->where('{$field}', \$expectedState)->update(['{$field}' => \$newState]) and "
                    .'only continue when exactly one row was affected; or lock the row with '
                    .'lockForUpdate() inside a transaction.',
                snippetStart: $line,
                snippetEnd: $line,
            );
        }

        return $findings;
    }

    /**
     * If the update() sets a status/state column to a literal, return the
     * column name; otherwise null.
     */
    private function stateFieldSetBy(Node\Expr\MethodCall $call): ?string
    {
        $first = $call->args[0] ?? null;
        if (! $first instanceof Node\Arg || ! $first->value instanceof Node\Expr\Array_) {
            return null;
        }

        foreach ($first->value->items as $item) {
            if ($item->key instanceof Node\Scalar\String_) {
                $key = strtolower($item->key->value);
                if (in_array($key, self::STATE_FIELDS, true)) {
                    return $key;
                }
            }
        }

        return null;
    }

    /**
     * Does the receiver chain contain a where() on the same field, e.g.
     * `->where('status', 'pending')`?
     */
    private function chainConditionsOn(Node\Expr\MethodCall $call, string $field): bool
    {
        $node = $call->var;

        while ($node instanceof Node\Expr\MethodCall || $node instanceof Node\Expr\NullsafeMethodCall) {
            $name = Ast::name($node->name);
            if ($name !== null && str_starts_with(strtolower($name), 'where')) {
                $arg = $node->args[0] ?? null;
                if ($arg instanceof Node\Arg
                    && $arg->value instanceof Node\Scalar\String_
                    && strtolower($arg->value->value) === $field) {
                    return true;
                }
            }

            $node = $node->var;
        }

        return false;
    }

    /**
     * Does the scope read that state field before the update on the record the
     * update targets? Instance updates are matched by variable. Static
     * query-builder updates are matched only when both the model class and a
     * key selector match a preceding `find()`/`findOrFail()` assignment.
     *
     * @param  Node|array<int, Node>  $scope
     * @param  array{kind: 'instance'|'builder', variable?: string, class?: string, key?: Node\Expr}  $target
     */
    private function scopeChecksField(
        Node|array $scope,
        string $field,
        Node\Expr\MethodCall $exclude,
        array $target,
    ): bool {
        $matches = $this->finder->find($scope, function (Node $node) use ($scope, $field, $exclude, $target): bool {
            if ($node === $exclude || $node->getStartLine() >= $exclude->getStartLine()) {
                return false;
            }

            if (($node instanceof Node\Expr\PropertyFetch || $node instanceof Node\Expr\NullsafePropertyFetch)
                && strtolower(Ast::name($node->name) ?? '') === $field) {
                $variable = Ast::rootVariable($node);

                if ($target['kind'] === 'instance') {
                    return $variable === $target['variable'];
                }

                return $variable !== null
                    && $this->variableWasReadByKey(
                        $scope,
                        $variable,
                        $target['class'],
                        $target['key'],
                        $node->getStartLine(),
                    );
            }

            return false;
        });

        return $matches !== [];
    }

    /**
     * Resolve an update target without guessing from unrelated field names.
     *
     * @return array{kind: 'instance'|'builder', variable?: string, class?: string, key?: Node\Expr}|null
     */
    private function updateTarget(Node\Expr\MethodCall $call): ?array
    {
        $variable = Ast::rootVariable($call->var);
        if ($variable !== null) {
            return ['kind' => 'instance', 'variable' => $variable];
        }

        $class = $this->builderClass($call->var);
        $key = $this->builderKey($call->var);

        if ($class === null || $key === null) {
            return null;
        }

        return ['kind' => 'builder', 'class' => $class, 'key' => $key];
    }

    private function builderClass(Node\Expr $node): ?string
    {
        while ($node instanceof Node\Expr\MethodCall || $node instanceof Node\Expr\NullsafeMethodCall) {
            $node = $node->var;
        }

        if (! $node instanceof Node\Expr\StaticCall || ! $node->class instanceof Node\Name) {
            return null;
        }

        return strtolower($node->class->toString());
    }

    private function builderKey(Node\Expr $node): ?Node\Expr
    {
        while ($node instanceof Node\Expr\MethodCall
            || $node instanceof Node\Expr\NullsafeMethodCall
            || $node instanceof Node\Expr\StaticCall) {
            $name = strtolower(Ast::name($node->name) ?? '');
            $args = $node->args;

            if ($name === 'wherekey' && isset($args[0])) {
                return $args[0]->value;
            }

            if ($name === 'where'
                && isset($args[0], $args[1])
                && $args[0]->value instanceof Node\Scalar\String_
                && in_array(strtolower($args[0]->value->value), self::KEY_FIELDS, true)) {
                return $args[1]->value;
            }

            if (! $node instanceof Node\Expr\MethodCall && ! $node instanceof Node\Expr\NullsafeMethodCall) {
                break;
            }

            $node = $node->var;
        }

        return null;
    }

    /**
     * Was `$variable` assigned from `Model::find($key)` before `$beforeLine`?
     *
     * @param  Node|array<int, Node>  $scope
     */
    private function variableWasReadByKey(
        Node|array $scope,
        string $variable,
        string $class,
        Node\Expr $key,
        int $beforeLine,
    ): bool {
        $matches = $this->finder->find($scope, function (Node $node) use ($variable, $class, $key, $beforeLine): bool {
            if (! $node instanceof Node\Expr\Assign
                || $node->getStartLine() >= $beforeLine
                || ! $node->var instanceof Node\Expr\Variable
                || $node->var->name !== $variable
                || ! $node->expr instanceof Node\Expr\StaticCall
                || ! $node->expr->class instanceof Node\Name
                || strtolower($node->expr->class->toString()) !== $class
                || ! in_array(strtolower(Ast::name($node->expr->name) ?? ''), self::KEY_READS, true)) {
                return false;
            }

            $argument = $node->expr->args[0] ?? null;

            return $argument instanceof Node\Arg && $this->sameExpression($argument->value, $key);
        });

        return $matches !== [];
    }

    /** Match the simple primary-key expressions that can be proven equal statically. */
    private function sameExpression(Node\Expr $left, Node\Expr $right): bool
    {
        if ($left instanceof Node\Expr\Variable && $right instanceof Node\Expr\Variable) {
            return is_string($left->name) && $left->name === $right->name;
        }

        if ($left instanceof Node\Scalar\String_ && $right instanceof Node\Scalar\String_) {
            return $left->value === $right->value;
        }

        return $left instanceof Node\Scalar\Int_
            && $right instanceof Node\Scalar\Int_
            && $left->value === $right->value;
    }
}
