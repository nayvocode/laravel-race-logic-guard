<?php

declare(strict_types=1);

namespace Nayvo\LaravelRaceGuard\Rules;

use Nayvo\LaravelRaceGuard\Analysis\FileContext;
use Nayvo\LaravelRaceGuard\Support\Ast;
use Nayvo\LaravelRaceGuard\Support\Severity;
use PhpParser\Node;

/**
 * A low-severity advisory: firstOrCreate()/updateOrCreate() are only safe
 * against duplicates under concurrency if the database enforces a unique index
 * on the lookup columns. Without one, two concurrent requests can both miss the
 * row and both insert.
 *
 *   User::firstOrCreate(['email' => $email], [...]);
 *   // safe ONLY if `email` has a unique index
 *
 * Static analysis cannot see the schema, so this is a reminder rather than a
 * defect — it is LOW severity and hidden by the default minimum_severity.
 */
final class MissingDatabaseUniquenessRule extends AbstractRule
{
    /** @var array<int, string> */
    private const UPSERT_METHODS = ['firstOrCreate', 'updateOrCreate', 'createOrFirst'];

    public function id(): string
    {
        return 'missing_database_uniqueness';
    }

    public function analyze(FileContext $file): array
    {
        $findings = [];
        $seen = [];

        foreach ($this->findInstances($file, Node\Expr\StaticCall::class) as $call) {
            $method = Ast::name($call->name);

            if (! in_array($method, self::UPSERT_METHODS, true)) {
                continue;
            }

            $line = $call->getStartLine();
            if (isset($seen[$line]) || ! $file->isInScope($line)) {
                continue;
            }
            $seen[$line] = true;

            $findings[] = $this->makeFinding(
                file: $file,
                severity: Severity::LOW,
                line: $line,
                title: "{$method}() relies on a database unique constraint.",
                message: "{$method}() prevents duplicates only if the database enforces a unique "
                    .'index on the lookup columns. Without one, two concurrent requests can both '
                    .'find the row missing and both insert it.',
                suggestion: 'Add a unique index (or composite unique index) on the attributes used '
                    .'to look the record up, and handle the resulting QueryException on the losing '
                    .'request.',
                snippetStart: $line,
                snippetEnd: $line,
            );
        }

        return $findings;
    }
}
