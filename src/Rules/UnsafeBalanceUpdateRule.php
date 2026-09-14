<?php

declare(strict_types=1);

namespace Nayvo\LaravelRaceGuard\Rules;

use Nayvo\LaravelRaceGuard\Analysis\FileContext;
use Nayvo\LaravelRaceGuard\Support\Ast;
use Nayvo\LaravelRaceGuard\Support\Severity;
use PhpParser\Node;

/**
 * Detects a check-then-act race across statements: a sufficiency/threshold
 * comparison on a fetched model's attribute, followed later in the same
 * method by an atomic decrement()/increment() of that same attribute, with
 * no row lock in between.
 *
 *   $wallet = PlayerCoin::where('player_id', $id)->first();
 *   if (! $wallet || $wallet->coins < $amount) {   // check (non-atomic read)
 *       abort(400);
 *   }
 *   // ...
 *   $wallet->decrement('coins', $amount);           // act (atomic, but unchecked)
 *
 * The decrement is atomic against lost updates, but the *guard* was evaluated
 * against a stale read, so two concurrent requests can both pass the check and
 * both debit — overspending a balance or overselling stock. This is the case
 * the single-statement rules miss, because the check and the act are far apart
 * and the mutation is an otherwise-"safe" atomic call.
 */
final class UnsafeBalanceUpdateRule extends AbstractRule
{
    /**
     * Reads that mark a variable as a freshly-fetched Eloquent model.
     *
     * @var array<int, string>
     */
    private const READ_METHODS = [
        'find', 'findOrFail', 'findOrNew', 'first', 'firstOrFail',
        'firstWhere', 'sole', 'findMany',
    ];

    /**
     * Instance mutators that change a single named attribute atomically.
     *
     * @var array<int, string>
     */
    private const ATOMIC_MUTATORS = ['decrement', 'increment'];

    public function id(): string
    {
        return 'unsafe_balance_update';
    }

    public function analyze(FileContext $file): array
    {
        $findings = [];
        $seen = [];

        foreach (Ast::thresholdGuards($file->ast) as $guard) {
            $scope = Ast::enclosingFunction($guard['node']) ?? $file->ast;

            // Only treat it as a model balance if the variable was fetched.
            if (! Ast::variableAssignedFrom($scope, $guard['var'], self::READ_METHODS)) {
                continue;
            }

            $mutation = $this->findAtomicMutatorAfter($scope, $guard['var'], $guard['attr'], $guard['line']);
            if ($mutation === null) {
                continue;
            }

            if (! $file->isInScope($mutation['line']) && ! $file->isInScope($guard['line'])) {
                continue;
            }

            // Respect a row lock / mutex around the whole flow.
            if (Ast::isGuarded($mutation['node'], $scope)) {
                continue;
            }

            $key = $guard['var'].'|'.$guard['attr'].'|'.$mutation['line'];
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;

            $findings[] = $this->makeFinding(
                file: $file,
                severity: Severity::HIGH,
                line: $mutation['line'],
                title: 'Potential check-then-act race on a balance or inventory value.',
                message: "\${$guard['var']}->{$guard['attr']} is checked on line {$guard['line']} "
                    ."and then changed with {$mutation['method']}() on line {$mutation['line']} "
                    .'without a lock. The check and the update are separate operations, so two '
                    .'concurrent requests can both pass the check against the same value and both '
                    ."apply the change — overspending or overselling. The atomic {$mutation['method']}() "
                    .'prevents lost updates but does not re-check the condition.',
                suggestion: 'Make the check and the update one atomic statement, e.g. '
                    ."Model::whereKey(\$id)->where('{$guard['attr']}', '>=', \$amount)"
                    ."->{$mutation['method']}('{$guard['attr']}', \$amount) and treat an "
                    .'affected-row count of 0 as failure; or load the row with lockForUpdate() '
                    .'inside a transaction before checking it.',
                snippetStart: $mutation['line'],
                snippetEnd: $mutation['line'],
            );
        }

        return $findings;
    }

    /**
     * The first atomic mutator call `$var->decrement('attr', ...)` that occurs
     * after $afterLine on the same variable and attribute.
     *
     * @param  Node|array<int, Node>  $scope
     * @return array{node: Node, line: int, method: string}|null
     */
    private function findAtomicMutatorAfter(Node|array $scope, string $var, string $attr, int $afterLine): ?array
    {
        $best = null;

        foreach (Ast::findCallsNamed($scope, self::ATOMIC_MUTATORS) as $call) {
            if (! $call instanceof Node\Expr\MethodCall && ! $call instanceof Node\Expr\NullsafeMethodCall) {
                continue;
            }

            if (Ast::rootVariable($call) !== $var || $call->getStartLine() <= $afterLine) {
                continue;
            }

            if ($this->firstArgString($call) !== $attr) {
                continue;
            }

            if ($best === null || $call->getStartLine() < $best['line']) {
                $best = [
                    'node' => $call,
                    'line' => $call->getStartLine(),
                    'method' => (string) Ast::name($call->name),
                ];
            }
        }

        return $best;
    }

    /**
     * The value of a call's first argument, when it is a plain string literal.
     */
    private function firstArgString(Node\Expr\MethodCall|Node\Expr\NullsafeMethodCall $call): ?string
    {
        $first = $call->args[0] ?? null;

        if (! $first instanceof Node\Arg || ! $first->value instanceof Node\Scalar\String_) {
            return null;
        }

        return $first->value->value;
    }
}
