<?php

declare(strict_types = 1);

// Fixture for ConnectionTransactionReturnTypeExtension. The extension resolves
// ConnectionInterface::transaction(fn () => $x) to the closure's return type
// instead of mixed. Each assertType() below pins the inferred type to the
// closure's declared/inferred return type. Classmap-autoloaded (not PSR-4) so
// Pint's psr_autoloading fixer leaves the namespaced classes untouched.

namespace ScriptDevelopment\PhpstanWarroomRules\Tests\Fixtures\ConnectionTransactionReturnType;

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
