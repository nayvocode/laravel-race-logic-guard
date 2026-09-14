<?php

declare(strict_types=1);

namespace Nayvo\LaravelRaceGuard\Rules;

use Nayvo\LaravelRaceGuard\Analysis\FileContext;
use Nayvo\LaravelRaceGuard\Support\Ast;
use Nayvo\LaravelRaceGuard\Support\Severity;
use PhpParser\Node;

/**
 * Detects an unguarded status/state transition: a query-builder update that
 * sets a `status`/`state` column to a fixed value, with no condition on the
 * current value, in a scope that has already read that status.
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

            // Only when the same scope reads/checks that state — the check-then-act intent.
            if (! $this->scopeChecksField($scope, $field, $call)) {
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
     * Does the scope read that state field elsewhere — a `->status` fetch or a
     * `where('status', ...)` — indicating a prior check?
     *
     * @param  Node|array<int, Node>  $scope
     */
    private function scopeChecksField(Node|array $scope, string $field, Node\Expr\MethodCall $exclude): bool
    {
        $matches = $this->finder->find($scope, function (Node $node) use ($field, $exclude): bool {
            if ($node === $exclude) {
                return false;
            }

            if (($node instanceof Node\Expr\PropertyFetch || $node instanceof Node\Expr\NullsafePropertyFetch)
                && strtolower(Ast::name($node->name) ?? '') === $field) {
                return true;
            }

            if (($node instanceof Node\Expr\MethodCall || $node instanceof Node\Expr\StaticCall)
                && str_starts_with(strtolower(Ast::name($node->name) ?? ''), 'where')) {
                $arg = $node->args[0] ?? null;

                return $arg instanceof Node\Arg
                    && $arg->value instanceof Node\Scalar\String_
                    && strtolower($arg->value->value) === $field;
            }

            return false;
        });

        return $matches !== [];
    }
}
