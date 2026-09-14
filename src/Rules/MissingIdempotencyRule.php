<?php

declare(strict_types=1);

namespace Nayvo\LaravelRaceGuard\Rules;

use Nayvo\LaravelRaceGuard\Analysis\FileContext;
use Nayvo\LaravelRaceGuard\Support\Ast;
use Nayvo\LaravelRaceGuard\Support\Severity;
use PhpParser\Node;

/**
 * Detects a queued job whose handle() performs an external side effect (an
 * HTTP call, a charge/capture, mail, notification) with no idempotency key.
 *
 *   class ChargeCustomer implements ShouldQueue
 *   {
 *       public function handle(): void
 *       {
 *           Http::post('https://gateway/charge', [...]);   // no idempotency key
 *       }
 *   }
 *
 * Queues are at-least-once: a job can be retried after a timeout even though
 * the effect already happened, so an external effect without a stable
 * idempotency key can fire twice (double charge, duplicate webhook, etc.).
 */
final class MissingIdempotencyRule extends AbstractRule
{
    /** @var array<int, string> Facades that reach an external system. */
    private const EXTERNAL_STATIC = ['Http', 'Mail', 'Notification'];

    /** @var array<int, string> Method names that imply an external money/side effect. */
    private const EXTERNAL_METHODS = ['charge', 'capture', 'pay', 'refund', 'transfer', 'send'];

    /** @var array<int, string> Interfaces that already give per-job uniqueness. */
    private const UNIQUE_INTERFACES = ['ShouldBeUnique', 'ShouldBeUniqueUntilProcessing'];

    /** @var array<int, string> Tokens whose presence signals idempotency handling. */
    private const IDEMPOTENCY_HINTS = ['idempot', 'uniqueid', 'event_id', 'eventid', 'firstorcreate', 'updateorcreate', 'insertorignore'];

    public function id(): string
    {
        return 'missing_idempotency';
    }

    public function analyze(FileContext $file): array
    {
        $findings = [];

        foreach ($this->findInstances($file, Node\Stmt\Class_::class) as $class) {
            if (! $this->isQueued($class) || $this->hasIdempotencySignal($class)) {
                continue;
            }

            $handle = $this->handleMethod($class);
            if ($handle === null || ! $this->hasExternalEffect($handle)) {
                continue;
            }

            $line = $class->getStartLine();
            if (! $file->isInScope($line)) {
                continue;
            }

            $name = Ast::name($class->name) ?? 'This job';

            $findings[] = $this->makeFinding(
                file: $file,
                severity: Severity::MEDIUM,
                line: $line,
                title: 'Queued job with an external side effect and no idempotency key.',
                message: "{$name} performs an external side effect in handle() but has no "
                    .'idempotency key. Queues deliver at least once, so a retry after a timeout can '
                    .'run the effect a second time (e.g. a double charge or duplicate webhook).',
                suggestion: 'Guard the effect with a stable idempotency key — pass one to the '
                    .'provider, or record a processed-event row with a unique constraint '
                    .'(firstOrCreate on the event id) and skip if it already exists. ShouldBeUnique '
                    .'reduces overlap but does not make the external effect idempotent.',
                snippetStart: $line,
                snippetEnd: $line,
            );
        }

        return $findings;
    }

    private function isQueued(Node\Stmt\Class_ $class): bool
    {
        foreach ($class->implements as $interface) {
            if (Ast::name($interface) === 'ShouldQueue') {
                return true;
            }
        }

        return false;
    }

    private function hasIdempotencySignal(Node\Stmt\Class_ $class): bool
    {
        foreach ($class->implements as $interface) {
            if (in_array(Ast::name($interface), self::UNIQUE_INTERFACES, true)) {
                return true;
            }
        }

        // Any idempotency-ish identifier or string literal anywhere in the class.
        foreach ($this->finder->findInstanceOf($class, Node\Identifier::class) as $id) {
            if ($this->looksLikeIdempotency($id->toString())) {
                return true;
            }
        }
        foreach ($this->finder->findInstanceOf($class, Node\Name::class) as $name) {
            if ($this->looksLikeIdempotency($name->toString())) {
                return true;
            }
        }
        foreach ($this->finder->findInstanceOf($class, Node\Scalar\String_::class) as $string) {
            if ($this->looksLikeIdempotency($string->value)) {
                return true;
            }
        }

        return false;
    }

    private function looksLikeIdempotency(string $text): bool
    {
        $text = strtolower($text);

        foreach (self::IDEMPOTENCY_HINTS as $hint) {
            if (str_contains($text, $hint)) {
                return true;
            }
        }

        return false;
    }

    private function handleMethod(Node\Stmt\Class_ $class): ?Node\Stmt\ClassMethod
    {
        foreach ($class->getMethods() as $method) {
            if (strtolower(Ast::name($method->name) ?? '') === 'handle') {
                return $method;
            }
        }

        return null;
    }

    private function hasExternalEffect(Node\Stmt\ClassMethod $handle): bool
    {
        if ($handle->stmts === null) {
            return false;
        }

        $calls = $this->finder->find($handle->stmts, function (Node $node): bool {
            if ($node instanceof Node\Expr\StaticCall) {
                return in_array(Ast::name($node->class), self::EXTERNAL_STATIC, true);
            }

            if ($node instanceof Node\Expr\MethodCall || $node instanceof Node\Expr\NullsafeMethodCall) {
                return in_array(strtolower(Ast::name($node->name) ?? ''), self::EXTERNAL_METHODS, true);
            }

            return false;
        });

        return $calls !== [];
    }
}
