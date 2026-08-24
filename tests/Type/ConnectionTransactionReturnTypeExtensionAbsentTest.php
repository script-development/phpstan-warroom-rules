<?php

declare(strict_types = 1);

namespace ScriptDevelopment\PhpstanWarroomRules\Tests\Type;

use PHPStan\Testing\TypeInferenceTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Standing positive control for ConnectionTransactionReturnTypeExtensionTest.
 *
 * Deliberately does NOT load extension.neon, so the fixture is analysed with the
 * extension absent and its assertions pin `mixed`. Together with the sibling
 * suite this brackets the extension: the sibling proves the assertions pass with
 * it registered, this proves they would fail without it — the teeth-check the
 * sibling cannot perform on its own, because Laravel 13's `@return TReturn`
 * generic satisfies a plain ConnectionInterface call unaided. WR-0855.
 */
final class ConnectionTransactionReturnTypeExtensionAbsentTest extends TypeInferenceTestCase
{
    /**
     * @return iterable<mixed>
     */
    public static function dataFileAsserts(): iterable
    {
        yield from self::gatherAssertTypes(
            __DIR__ . '/../Fixtures/ConnectionTransactionReturnType/extension-absent-control.php',
        );
    }

    #[DataProvider('dataFileAsserts')]
    public function testFileAsserts(string $assertType, string $file, ...$args): void
    {
        $this->assertFileAsserts($assertType, $file, ...$args);
    }
}
