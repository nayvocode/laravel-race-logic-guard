<?php

declare(strict_types=1);

namespace Nayvo\LaravelRaceGuard\Rules;

use Nayvo\LaravelRaceGuard\Analysis\FileContext;
use Nayvo\LaravelRaceGuard\Analysis\Finding;
use Nayvo\LaravelRaceGuard\Support\Ast;
use Nayvo\LaravelRaceGuard\Support\Severity;
use PhpParser\Node;

/**
 * Detects check-then-create races:
 *
 *   if (! Order::where('reference', $reference)->exists()) {
 *       Order::create([...]);
 *   }
 *
 * Two requests can both find the record missing and both create it.
 */
final class CheckThenCreateRule extends AbstractRule
{
    /** @var array<int, string> Existence checks in the condition. */
    private const EXISTENCE_METHODS = ['exists', 'doesntExist', 'count'];

    /** @var array<int, string> Lookups that are only a check when null-tested. */
    private const LOOKUP_METHODS = ['first', 'find', 'firstWhere', 'findOrNew'];

    /** @var array<int, string> Non-atomic creation calls. */
    private const UNSAFE_CREATE = ['create', 'forceCreate', 'insert', 'insertGetId', 'make'];

    /** @var array<int, string> Atomic creation calls that make the block safe. */
    private const SAFE_CREATE = ['firstOrCreate', 'updateOrCreate', 'insertOrIgnore', 'createOrFirst'];

    private const PERSIST_METHODS = ['save', 'saveOrFail'];

    public function id(): string
    {
        return 'check_then_create';
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

        if (! $this->conditionIsExistenceCheck($if->cond)) {
            return null;
        }

        $body = $this->branchStatements($if);

        // An atomic create makes the whole thing safe — say nothing.
        if (Ast::findCallsNamed($body, self::SAFE_CREATE) !== []) {
            return null;
        }

        if (! $this->branchCreates($body)) {
            return null;
        }

        $scope = Ast::enclosingFunction($if) ?? $file->ast;

        // A Cache::lock() / mutex around the block is a deliberate guard.
        if (Ast::isGuarded($if, $scope)) {
            return null;
        }

        return $this->makeFinding(
            file: $file,
            severity: Severity::HIGH,
            line: $if->getStartLine(),
            title: 'Potential check-then-create race condition.',
            message: 'The code checks whether a record exists and then creates it. '
                .'Two requests can both observe that the record is missing and both '
                .'proceed to create it, producing a duplicate.',
            suggestion: 'Enforce uniqueness with a database unique constraint as the '
                .'final line of defence, and/or use an atomic upsert such as '
                .'firstOrCreate(), updateOrCreate() or insertOrIgnore().',
            snippetStart: $if->getStartLine(),
            snippetEnd: $this->blockEndLine($if),
        );
    }

    private function conditionIsExistenceCheck(Node\Expr $cond): bool
    {
        if (Ast::findCallsNamed($cond, self::EXISTENCE_METHODS) !== []) {
            return true;
        }

        $hasLookup = Ast::findCallsNamed($cond, self::LOOKUP_METHODS) !== [];

        return $hasLookup && $this->conditionIsNegativeTest($cond);
    }

    /**
     * Is the condition a "not / is null / empty" style test, i.e. the sort of
     * thing that gates a create on the *absence* of a record?
     */
    private function conditionIsNegativeTest(Node\Expr $cond): bool
    {
        if ($cond instanceof Node\Expr\BooleanNot) {
            return true;
        }

        // is_null(...) / empty(...)
        $funcCalls = $this->finder->findInstanceOf($cond, Node\Expr\FuncCall::class);
        foreach ($funcCalls as $call) {
            if (in_array(Ast::name($call->name), ['is_null', 'empty'], true)) {
                return true;
            }
        }

        // === null / !== null / == null / != null
        $comparisons = $this->finder->find($cond, static function (Node $node): bool {
            return $node instanceof Node\Expr\BinaryOp\Identical
                || $node instanceof Node\Expr\BinaryOp\NotIdentical
                || $node instanceof Node\Expr\BinaryOp\Equal
                || $node instanceof Node\Expr\BinaryOp\NotEqual;
        });

        foreach ($comparisons as $cmp) {
            /** @var Node\Expr\BinaryOp $cmp */
            if ($this->isNullConst($cmp->left) || $this->isNullConst($cmp->right)) {
                return true;
            }
        }

        return false;
    }

    private function isNullConst(Node $node): bool
    {
        return $node instanceof Node\Expr\ConstFetch
            && strtolower(Ast::name($node->name) ?? '') === 'null';
    }

    /**
     * @param  array<int, Node>  $body
     */
    private function branchCreates(array $body): bool
    {
        if (Ast::findCallsNamed($body, self::UNSAFE_CREATE) !== []) {
            return true;
        }

        // new Model(...) followed by a ->save() somewhere in the branch.
        $hasNew = $this->finder->findInstanceOf($body, Node\Expr\New_::class) !== [];
        $hasSave = Ast::findCallsNamed($body, self::PERSIST_METHODS) !== [];

        return $hasNew && $hasSave;
    }

    /**
     * All statements that run when the "record is missing" branch is taken,
     * including any else / elseif branches (a create can live in either).
     *
     * @return array<int, Node>
     */
    private function branchStatements(Node\Stmt\If_ $if): array
    {
        $stmts = $if->stmts;

        foreach ($if->elseifs as $elseif) {
            $stmts = array_merge($stmts, $elseif->stmts);
        }

        if ($if->else !== null) {
            $stmts = array_merge($stmts, $if->else->stmts);
        }

        return $stmts;
    }

    private function blockEndLine(Node\Stmt\If_ $if): int
    {
        return $if->getEndLine() > 0 ? $if->getEndLine() : $if->getStartLine();
    }
}
