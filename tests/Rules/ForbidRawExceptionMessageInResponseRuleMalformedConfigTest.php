<?php

declare(strict_types = 1);

namespace ScriptDevelopment\PhpstanWarroomRules\Tests\Rules;

use InvalidArgumentException;
use PHPStan\Testing\PHPStanTestCase;
use ScriptDevelopment\PhpstanWarroomRules\Rules\ForbidRawExceptionMessageInResponseRule;

/**
 * The consumer path: a malformed sink in a `phpstan.neon` that includes the
 * shipped `extension.neon` passes the parameter schema (`listOf(string())`) and
 * must still stop at rule resolution instead of building a rule with no sink.
 */
final class ForbidRawExceptionMessageInResponseRuleMalformedConfigTest extends PHPStanTestCase
{
    public function testAMalformedSinkFromNeonStopsRuleResolution(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(
            "rawExceptionMessageSinks entry 'App\\Support\\InvoiceLog:recordError' is not a `FQCN::method` signature",
        );

        self::getContainer()->getByType(ForbidRawExceptionMessageInResponseRule::class);
    }

    /**
     * @return array<int, string>
     */
    public static function getAdditionalConfigFiles(): array
    {
        return [
            __DIR__ . '/../../extension.neon',
            __DIR__ . '/../Fixtures/RawExceptionMessageInResponse/malformed-sink.neon',
        ];
    }
}
