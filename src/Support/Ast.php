<?php

declare(strict_types=1);

namespace Nayvo\LaravelRaceGuard\Support;

use PhpParser\Node;
use PhpParser\NodeFinder;

/**
 * Static helpers for reasoning about a php-parser AST. Kept deliberately
 * small and side-effect free so rules stay readable.
 */
final class Ast
{
    /**
     * Method names that indicate a pessimistic lock or atomic guard is in
     * play. When any of these appear inside the same function as a suspicious
     * pattern, RaceGuard treats the code as intentionally guarded and stays
     * quiet — favouring a low false-positive rate.
     *
     * @var array<int, string>
     */
    public const LOCK_METHODS = [
        'lockForUpdate',
        'sharedLock',
        'lock',
        'block',
    ];

    /**
     * Atomic Eloquent / query-builder operations that are safe under
     * concurrency and therefore never flagged.
     *
     * @var array<int, string>
     */
    public const ATOMIC_METHODS = [
        'increment',
        'decrement',
        'incrementEach',
        'decrementEach',
    ];

    /**
     * Resolve a node's name to a plain string, where one exists.
     */
    public static function name(?Node $node): ?string
    {
        if ($node instanceof Node\Identifier || $node instanceof Node\Name) {
            return $node->toString();
        }

        if ($node instanceof Node\Expr\Variable && is_string($node->name)) {
            return $node->name;
        }

        return null;
    }

    /**
     * The variable name behind an expression like `$order` or `$order->status`.
     */
    public static function rootVariable(Node $node): ?string
    {
        while ($node instanceof Node\Expr\PropertyFetch
            || $node instanceof Node\Expr\NullsafePropertyFetch
            || $node instanceof Node\Expr\MethodCall
            || $node instanceof Node\Expr\NullsafeMethodCall
            || $node instanceof Node\Expr\ArrayDimFetch) {
            $node = $node->var;
        }

        if ($node instanceof Node\Expr\Variable && is_string($node->name)) {
            return $node->name;
        }

        return null;
    }

    /**
     * For `$var->prop`, return "prop" (only when the base is a simple variable).
     */
    public static function propertyName(Node $node): ?string
    {
        if (! $node instanceof Node\Expr\PropertyFetch && ! $node instanceof Node\Expr\NullsafePropertyFetch) {
            return null;
        }

        if (! $node->var instanceof Node\Expr\Variable) {
            return null;
        }

        return self::name($node->name);
    }

    /**
     * Walk parent links to find the nearest enclosing function or method.
     * Requires ParentConnectingVisitor to have run first.
     */
    public static function enclosingFunction(Node $node): ?Node\FunctionLike
    {
        $current = $node->getAttribute('parent');

        while ($current instanceof Node) {
            if ($current instanceof Node\FunctionLike) {
                return $current;
            }

            $current = $current->getAttribute('parent');
        }

        return null;
    }

    /**
     * Does the given scope contain a pessimistic lock or atomic guard,
     * indicating the developer has deliberately handled concurrency?
     *
     * @param  Node|array<int, Node>|null  $scope
     */
    public static function scopeIsGuarded(Node|array|null $scope): bool
    {
        if ($scope === null || $scope === []) {
            return false;
        }

        $finder = new NodeFinder;

        // Any ->lockForUpdate()/->sharedLock()/->lock() call.
        $methodCalls = $finder->findInstanceOf($scope, Node\Expr\MethodCall::class);
        foreach ($methodCalls as $call) {
            if (in_array(self::name($call->name), self::LOCK_METHODS, true)) {
                return true;
            }
        }

        // Cache::lock(...) / Redis::... style static guards.
        $staticCalls = $finder->findInstanceOf($scope, Node\Expr\StaticCall::class);
        foreach ($staticCalls as $call) {
            if (self::name($call->name) === 'lock') {
                return true;
            }
        }

        return false;
    }

    /**
     * True when $node sits inside a callback passed to a lock-protected chain,
     * e.g. the closure given to `Cache::lock(...)->get(fn () => ...)` or
     * `->block(...)`. The lock lives on the receiver of an ancestor call rather
     * than inside the callback's own scope, so a plain scope scan misses it.
     */
    public static function isWithinLockCallback(Node $node): bool
    {
        $current = $node->getAttribute('parent');

        while ($current instanceof Node) {
            if (($current instanceof Node\Expr\MethodCall || $current instanceof Node\Expr\NullsafeMethodCall)
                && self::findCallsNamed($current->var, self::LOCK_METHODS) !== []) {
                return true;
            }

            $current = $current->getAttribute('parent');
        }

        return false;
    }

    /**
     * Combined guard check: is the node protected by a lock/atomic guard in
     * its enclosing scope, or wrapped in a lock-protected callback?
     *
     * @param  Node|array<int, Node>|null  $scope
     */
    public static function isGuarded(Node $node, Node|array|null $scope): bool
    {
        return self::scopeIsGuarded($scope) || self::isWithinLockCallback($node);
    }

    /**
     * Is $var assigned anywhere in $scope from a call to one of $methodNames,
     * e.g. `$order = Order::find(...)` — the fetched-model heuristic.
     *
     * @param  Node|array<int, Node>  $scope
     * @param  array<int, string>  $methodNames
     */
    public static function variableAssignedFrom(Node|array $scope, string $var, array $methodNames): bool
    {
        $finder = new NodeFinder;

        $reads = $finder->find($scope, static function (Node $node) use ($var, $methodNames): bool {
            if (! $node instanceof Node\Expr\Assign) {
                return false;
            }

            if (! $node->var instanceof Node\Expr\Variable || $node->var->name !== $var) {
                return false;
            }

            return self::findCallsNamed($node->expr, $methodNames) !== [];
        });

        return $reads !== [];
    }

    /**
     * Find every sufficiency/threshold comparison (<, <=, >, >=) inside $scope
     * whose operand is a simple `$var->attr` property fetch (casts unwrapped).
     *
     * @param  Node|array<int, Node>  $scope
     * @return array<int, array{var: string, attr: string, line: int, node: Node}>
     */
    public static function thresholdGuards(Node|array $scope): array
    {
        $finder = new NodeFinder;
        $guards = [];

        $comparisons = $finder->find($scope, static function (Node $node): bool {
            return $node instanceof Node\Expr\BinaryOp\Smaller
                || $node instanceof Node\Expr\BinaryOp\SmallerOrEqual
                || $node instanceof Node\Expr\BinaryOp\Greater
                || $node instanceof Node\Expr\BinaryOp\GreaterOrEqual;
        });

        foreach ($comparisons as $cmp) {
            if (! $cmp instanceof Node\Expr\BinaryOp) {
                continue;
            }

            foreach ([$cmp->left, $cmp->right] as $operand) {
                $fetch = self::thresholdOperand($operand);

                if ($fetch !== null) {
                    $guards[] = [
                        'var' => $fetch['var'],
                        'attr' => $fetch['attr'],
                        'line' => $cmp->getStartLine(),
                        'node' => $cmp,
                    ];
                }
            }
        }

        return $guards;
    }

    /**
     * Unwrap a cast and return the `$var->attr` behind an operand, when it is a
     * property fetch on a simple variable.
     *
     * @return array{var: string, attr: string}|null
     */
    private static function thresholdOperand(Node\Expr $expr): ?array
    {
        if ($expr instanceof Node\Expr\Cast) {
            $expr = $expr->expr;
        }

        if (! $expr instanceof Node\Expr\PropertyFetch && ! $expr instanceof Node\Expr\NullsafePropertyFetch) {
            return null;
        }

        if (! $expr->var instanceof Node\Expr\Variable || ! is_string($expr->var->name)) {
            return null;
        }

        $attr = self::name($expr->name);
        if ($attr === null) {
            return null;
        }

        return ['var' => $expr->var->name, 'attr' => $attr];
    }

    /**
     * True when the node is a method call `$x->name(...)` whose name matches.
     *
     * @param  array<int, string>  $names
     */
    public static function isMethodCall(Node $node, array $names): bool
    {
        return ($node instanceof Node\Expr\MethodCall || $node instanceof Node\Expr\NullsafeMethodCall)
            && in_array(self::name($node->name), $names, true);
    }

    /**
     * True when the node is a static call `Class::name(...)` whose method
     * name matches (and, optionally, whose class matches).
     *
     * @param  array<int, string>  $methods
     * @param  array<int, string>|null  $classes
     */
    public static function isStaticCall(Node $node, array $methods, ?array $classes = null): bool
    {
        if (! $node instanceof Node\Expr\StaticCall) {
            return false;
        }

        if (! in_array(self::name($node->name), $methods, true)) {
            return false;
        }

        if ($classes === null) {
            return true;
        }

        return in_array(self::name($node->class), $classes, true);
    }

    /**
     * Find every call (method or static) anywhere under $scope whose method
     * name is in $names.
     *
     * @param  Node|array<int, Node>  $scope
     * @param  array<int, string>  $names
     * @return array<int, Node>
     */
    public static function findCallsNamed(Node|array $scope, array $names): array
    {
        $finder = new NodeFinder;

        return $finder->find($scope, static function (Node $node) use ($names): bool {
            if ($node instanceof Node\Expr\MethodCall
                || $node instanceof Node\Expr\NullsafeMethodCall
                || $node instanceof Node\Expr\StaticCall) {
                return in_array(self::name($node->name), $names, true);
            }

            return false;
        });
    }
}
