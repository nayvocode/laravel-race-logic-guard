<?php

declare(strict_types=1);

namespace Nayvo\LaravelRaceGuard\Rules;

use Nayvo\LaravelRaceGuard\Analysis\FileContext;
use Nayvo\LaravelRaceGuard\Support\Ast;
use Nayvo\LaravelRaceGuard\Support\Severity;
use PhpParser\Node;

/**
 * The manual-save sibling of the balance rule: a sufficiency/threshold check on
 * a fetched model's attribute, followed by a plain reassignment of that
 * attribute and a save(), with no lock.
 *
 *   $wallet = Wallet::find($id);
 *   if ($wallet->balance < $amount) {
 *       abort(400);
 *   }
 *   $wallet->balance = $newBalance;   // recomputed elsewhere, then written
 *   $wallet->save();
 *
 * The read-modify-write rule only catches self-referential arithmetic
 * (`= $wallet->balance - $x`); this catches the case where the new value is
 * computed separately, so the attribute is still written based on a stale,
 * unlocked read.
 */
final class GuardThenSaveRule extends AbstractRule
{
    /** @var array<int, string> */
    private const READ_METHODS = [
        'find', 'findOrFail', 'findOrNew', 'first', 'firstOrFail', 'firstWhere', 'sole',
    ];

    /** @var array<int, string> */
    private const PERSIST_METHODS = ['save', 'saveOrFail'];

    public function id(): string
    {
        return 'guard_then_save';
    }

    public function analyze(FileContext $file): array
    {
        $findings = [];
        $seen = [];

        foreach (Ast::thresholdGuards($file->ast) as $guard) {
            $scope = Ast::enclosingFunction($guard['node']) ?? $file->ast;

            if (! Ast::variableAssignedFrom($scope, $guard['var'], self::READ_METHODS)) {
                continue;
            }

            $assignment = $this->reassignmentAfter($scope, $guard['var'], $guard['attr'], $guard['line']);
            if ($assignment === null) {
                continue;
            }

            // Must actually be persisted after the reassignment.
            if (! $this->persistsVariable($scope, $guard['var'])) {
                continue;
            }

            if (! $file->isInScope($assignment->getStartLine()) && ! $file->isInScope($guard['line'])) {
                continue;
            }

            if (Ast::isGuarded($assignment, $scope)) {
                continue;
            }

            $key = $guard['var'].'|'.$guard['attr'].'|'.$assignment->getStartLine();
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;

            $findings[] = $this->makeFinding(
                file: $file,
                severity: Severity::HIGH,
                line: $assignment->getStartLine(),
                title: 'Potential check-then-act race on a balance or inventory value.',
                message: "\${$guard['var']}->{$guard['attr']} is checked on line {$guard['line']} and then "
                    .'reassigned and saved without a lock. Two concurrent requests can both pass '
                    .'the check against the same stale value and both write, losing one update.',
                suggestion: 'Load the row with lockForUpdate() inside a transaction before checking '
                    .'and writing, or perform the change as an atomic conditional update instead of '
                    .'reading, deciding and saving in separate steps.',
                snippetStart: $assignment->getStartLine(),
                snippetEnd: $assignment->getStartLine(),
            );
        }

        return $findings;
    }

    /**
     * A plain assignment `$var->attr = <expr>` after $afterLine whose right-hand
     * side does NOT read `$var->attr` back (that self-referential case belongs
     * to the read-modify-write rule).
     *
     * @param  Node|array<int, Node>  $scope
     */
    private function reassignmentAfter(Node|array $scope, string $var, string $attr, int $afterLine): ?Node\Expr\Assign
    {
        $best = null;

        $assigns = $this->finder->find($scope, function (Node $node) use ($var, $attr, $afterLine): bool {
            if (! $node instanceof Node\Expr\Assign || $node->getStartLine() <= $afterLine) {
                return false;
            }

            $target = $node->var;
            if (! $target instanceof Node\Expr\PropertyFetch && ! $target instanceof Node\Expr\NullsafePropertyFetch) {
                return false;
            }

            if (! $target->var instanceof Node\Expr\Variable
                || $target->var->name !== $var
                || Ast::name($target->name) !== $attr) {
                return false;
            }

            // Skip self-referential arithmetic (owned by read_modify_write).
            return ! $this->expressionReadsProperty($node->expr, $var, $attr);
        });

        foreach ($assigns as $assign) {
            /** @var Node\Expr\Assign $assign */
            if ($best === null || $assign->getStartLine() < $best->getStartLine()) {
                $best = $assign;
            }
        }

        return $best;
    }

    private function expressionReadsProperty(Node $expr, string $var, string $attr): bool
    {
        $matches = $this->finder->find($expr, static function (Node $node) use ($var, $attr): bool {
            return ($node instanceof Node\Expr\PropertyFetch || $node instanceof Node\Expr\NullsafePropertyFetch)
                && $node->var instanceof Node\Expr\Variable
                && $node->var->name === $var
                && Ast::name($node->name) === $attr;
        });

        return $matches !== [];
    }

    /**
     * @param  Node|array<int, Node>  $scope
     */
    private function persistsVariable(Node|array $scope, string $var): bool
    {
        foreach (Ast::findCallsNamed($scope, self::PERSIST_METHODS) as $call) {
            if (($call instanceof Node\Expr\MethodCall || $call instanceof Node\Expr\NullsafeMethodCall)
                && Ast::rootVariable($call) === $var) {
                return true;
            }
        }

        return false;
    }
}
