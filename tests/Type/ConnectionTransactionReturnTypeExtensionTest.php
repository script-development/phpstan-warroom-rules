<?php

declare(strict_types = 1);

namespace ScriptDevelopment\PhpstanWarroomRules\Tests\Type;

use PHPStan\Testing\TypeInferenceTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Direct type-inference coverage for ConnectionTransactionReturnTypeExtension.
 *
 * The extension is registered through extension.neon (same config consumers
 * load), so the fixture's assertType() calls are resolved with the extension
 * active.
 *
 * WR-0855 — what unregistering the extension proves depends on the resolved
 * illuminate/database major, so this suite alone does not measure its teeth.
 * Laravel 11/12 annotate ConnectionInterface::transaction() `@return mixed`:
 * there every TransactionCaller assertion fails without the extension. Laravel
 * 13 annotates it `@template TReturn`/`@return TReturn` and satisfies those
 * assertions unaided. The LegacyAnnotatedCaller assertions call through an
 * interface carrying the 11/12 `@return mixed` and therefore discriminate on
 * every major; ConnectionTransactionReturnTypeExtensionAbsentTest is the
 * standing control proving they do, and the CI lowest-Laravel job covers the
 * real upstream annotation.
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
