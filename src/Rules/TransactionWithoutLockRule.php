<?php

declare(strict_types=1);

namespace Nayvo\LaravelRaceGuard\Rules;

use Nayvo\LaravelRaceGuard\Analysis\FileContext;
use Nayvo\LaravelRaceGuard\Support\Ast;
use Nayvo\LaravelRaceGuard\Support\Severity;
use PhpParser\Node;

/**
 * Detects a read-modify-write performed inside DB::transaction() without a
 * row lock:
 *
 *   DB::transaction(function () use ($id, $amount) {
 *       $wallet = Wallet::find($id);        // read — not locked
 *       $wallet->balance -= $amount;
 *       $wallet->save();                    // write
 *   });
 *
 * A transaction gives you atomicity and rollback, but on the default READ
 * COMMITTED isolation level it does NOT stop two transactions from reading the
 * same row and overwriting each other. Developers routinely assume it does.
 * The fix is a pessimistic lock (lockForUpdate) on the read inside the
 * transaction.
 */
final class TransactionWithoutLockRule extends AbstractRule
{
    /** @var array<int, string> */
    private const READ_METHODS = [
        'find', 'findOrFail', 'findOrNew', 'first', 'firstOrFail', 'firstWhere', 'sole',
    ];

    /** @var array<int, string> */
    private const MUTATORS = ['save', 'saveOrFail', 'update', 'decrement', 'increment', 'push', 'delete'];

    public function id(): string
    {
        return 'transaction_without_lock';
    }

    public function analyze(FileContext $file): array
    {
        $findings = [];

        foreach ($this->transactionClosures($file) as $closure) {
            // A pessimistic lock anywhere in the transaction body clears it.
            if (Ast::scopeIsGuarded($closure)) {
                continue;
            }

            $var = $this->fetchedThenMutatedVariable($closure);
            if ($var === null) {
                continue;
            }

            if (! $file->isInScope($closure->getStartLine())) {
                continue;
            }

            $findings[] = $this->makeFinding(
                file: $file,
                severity: Severity::HIGH,
                line: $closure->getStartLine(),
                title: 'Read-modify-write inside a transaction without a row lock.',
                message: "\${$var} is read and then modified inside DB::transaction() without "
                    .'lockForUpdate(). A transaction alone does not prevent two requests from '
                    .'reading the same row and overwriting each other on the default isolation '
                    .'level — it only makes the change atomic.',
                suggestion: 'Load the row for update inside the transaction, e.g. '
                    ."\${$var} = Model::whereKey(\$id)->lockForUpdate()->firstOrFail(); so "
                    .'concurrent transactions serialise on the locked row.',
                snippetStart: $closure->getStartLine(),
                snippetEnd: $closure->getStartLine(),
            );
        }

        return $findings;
    }

    /**
     * Closures passed to DB::transaction(...).
     *
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
     * A variable that is both fetched from the DB (unlocked) and later mutated
     * inside the closure — the read-modify-write signature.
     */
    private function fetchedThenMutatedVariable(Node\FunctionLike $closure): ?string
    {
        foreach (Ast::findCallsNamed($closure, self::MUTATORS) as $call) {
            if (! $call instanceof Node\Expr\MethodCall && ! $call instanceof Node\Expr\NullsafeMethodCall) {
                continue;
            }

            $var = Ast::rootVariable($call);
            if ($var !== null && Ast::variableAssignedFrom($closure, $var, self::READ_METHODS)) {
                return $var;
            }
        }

        // Also cover `$m->attr = ...; $m->save();` where the mutation is a
        // property assignment rather than a method call — still needs a var
        // that was fetched and then saved (covered by the loop above via save).
        return null;
    }
}
