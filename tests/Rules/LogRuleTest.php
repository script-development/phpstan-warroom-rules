<?php

declare(strict_types = 1);

namespace ScriptDevelopment\PhpstanWarroomRules\Tests\Rules;

use PHPStan\Rules\Rule;
use PHPStan\Testing\RuleTestCase;
use ScriptDevelopment\PhpstanWarroomRules\Rules\LogRule;

/**
 * @extends RuleTestCase<LogRule>
 */
final class LogRuleTest extends RuleTestCase
{
    public function testFlagsUpdateOnLogClass(): void
    {
        $this->analyse(
            [__DIR__ . '/../Fixtures/LogRule/UpdatesAuditLog.php'],
            [
                [
                    'Logs should not be updated or deleted.',
                    11,
                ],
            ],
        );
    }

    public function testFlagsDeleteOnLogClass(): void
    {
        $this->analyse(
            [__DIR__ . '/../Fixtures/LogRule/DeletesAuditLog.php'],
            [
                [
                    'Logs should not be updated or deleted.',
                    11,
                ],
            ],
        );
    }

    public function testIgnoresUpdateOnRegularClass(): void
    {
        $this->analyse(
            [__DIR__ . '/../Fixtures/LogRule/UpdatesRegularModel.php'],
            [],
        );
    }

    protected function getRule(): Rule
    {
        return new LogRule;
    }
}
