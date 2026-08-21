<?php

declare(strict_types = 1);

// Fixture for ConnectionTransactionReturnTypeExtension. The extension resolves
// ConnectionInterface::transaction(fn () => $x) to the closure's return type.
// Each assertType() below pins the inferred type to the closure's
// declared/inferred return type. Classmap-autoloaded (not PSR-4) so Pint's
// psr_autoloading fixer leaves the namespaced classes untouched.
//
// WR-0855: which of these assertions actually has teeth depends on the resolved
// illuminate/database major. Laravel 11 and 12 annotate
// ConnectionInterface::transaction() `@return mixed`, so every TransactionCaller
// assertion fails there without the extension. Laravel 13 annotates it
// `@template TReturn`/`@return TReturn` and satisfies them on its own. Only
// LegacyAnnotatedCaller — which calls through an interface carrying the 11/12
// `@return mixed` — discriminates on all three majors, and
// extension-absent-control.php is the standing control proving it still does.

namespace ScriptDevelopment\PhpstanWarroomRules\Tests\Fixtures\ConnectionTransactionReturnType;

use Closure;
use Illuminate\Database\ConnectionInterface;

use function PHPStan\Testing\assertType;

final class TransactionResult
{
    public function __construct(
        public int $id,
    ) {}
}

final class TransactionCaller
{
    public function returnsInt(ConnectionInterface $connection): void
    {
        $result = $connection->transaction(fn(): int => 42);

        // Closure body narrows to the constant 42; the extension forwards the
        // precise inferred return type (not the widened `int`), proving it
        // reads the closure acceptor's return type rather than the declaration.
        assertType('42', $result);
    }

    public function returnsObject(ConnectionInterface $connection): void
    {
        $result = $connection->transaction(fn(): TransactionResult => new TransactionResult(1));

        assertType(TransactionResult::class, $result);
    }

    public function returnsNullable(ConnectionInterface $connection, ?string $maybe): void
    {
        // A captured nullable variable keeps the union intact, so the extension
        // forwards the genuine `string|null` rather than a narrowed constant.
        $result = $connection->transaction(fn(): ?string => $maybe);

        assertType('string|null', $result);
    }

    public function returnsArray(ConnectionInterface $connection): void
    {
        $result = $connection->transaction(fn(): array => ['a', 'b']);

        assertType('array{\'a\', \'b\'}', $result);
    }

    public function returnsWidenedScalar(ConnectionInterface $connection, int $value): void
    {
        // A captured variable defeats constant folding, so the closure's
        // inferred return type is the widened `int` — confirming the extension
        // forwards whatever the acceptor resolves, constant or not.
        $result = $connection->transaction(fn(): int => $value);

        assertType('int', $result);
    }
}

/**
 * Carries the Laravel 11/12 `ConnectionInterface::transaction()` docblock
 * verbatim, so the assertions below discriminate the extension's presence on
 * every supported major — including Laravel 13, whose own `@return TReturn`
 * generic makes the TransactionCaller assertions above pass unaided. WR-0855.
 */
interface LegacyAnnotatedConnection extends ConnectionInterface
{
    /**
     * @param int $attempts
     *
     * @return mixed the closure's result, untyped as on Laravel 11/12
     */
    public function transaction(Closure $callback, $attempts = 1);
}

final class LegacyAnnotatedCaller
{
    public function returnsInt(LegacyAnnotatedConnection $connection): void
    {
        $result = $connection->transaction(fn(): int => 42);

        assertType('42', $result);
    }

    public function returnsNullable(LegacyAnnotatedConnection $connection, ?string $maybe): void
    {
        $result = $connection->transaction(fn(): ?string => $maybe);

        assertType('string|null', $result);
    }
}
