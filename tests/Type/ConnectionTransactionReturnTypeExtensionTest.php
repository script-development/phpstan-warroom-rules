<?php

declare(strict_types = 1);

namespace ScriptDevelopment\PhpstanWarroomRules\Tests\Type;

use PHPStan\Testing\TypeInferenceTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Direct type-inference coverage for ConnectionTransactionReturnTypeExtension.
 *
 * The extension is registered through extension.neon (same config consumers
 * load), so the assertType() calls in the fixture are resolved with the
 * extension active. Without it, ConnectionInterface::transaction() returns
 * mixed and every assertion below would fail.
 */
final class ConnectionTransactionReturnTypeExtensionTest extends TypeInferenceTestCase
{
    /**
     * @return iterable<mixed>
     */
    public static function dataFileAsserts(): iterable
    {
        yield from self::gatherAssertTypes(
            __DIR__ . '/../Fixtures/ConnectionTransactionReturnType/transaction-return-type.php',
        );
    }

    #[DataProvider('dataFileAsserts')]
    public function testFileAsserts(string $assertType, string $file, ...$args): void
    {
        $this->assertFileAsserts($assertType, $file, ...$args);
    }

    public static function getAdditionalConfigFiles(): array
    {
        return [
            __DIR__ . '/../../extension.neon',
        ];
    }
}
