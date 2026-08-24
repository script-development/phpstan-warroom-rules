<?php

declare(strict_types = 1);

// Positive control for ConnectionTransactionReturnTypeExtensionAbsentTest, which
// analyses this file WITHOUT extension.neon. The calls below are the same shape as
// LegacyAnnotatedCaller's, so `mixed` here is what proves those assertions have
// teeth rather than being carried by an upstream annotation.
//
// WR-0855: this exists because Pint's `no_superfluous_phpdoc_tags` fixer
// (`allow_mixed: false`) once stripped the `@return mixed` off
// LegacyAnnotatedConnection, silently making the whole fixture pass with the
// extension unregistered. If that tag is ever lost again the assertions here
// resolve to the closure's return type and this file goes red.

namespace ScriptDevelopment\PhpstanWarroomRules\Tests\Fixtures\ConnectionTransactionReturnType;

use function PHPStan\Testing\assertType;

final class ExtensionAbsentControlCaller
{
    public function returnsInt(LegacyAnnotatedConnection $connection): void
    {
        $result = $connection->transaction(fn(): int => 42);

        assertType('mixed', $result);
    }

    public function returnsNullable(LegacyAnnotatedConnection $connection, ?string $maybe): void
    {
        $result = $connection->transaction(fn(): ?string => $maybe);

        assertType('mixed', $result);
    }
}
