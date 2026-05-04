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

    public function testFlagsForceDeleteOnLogClass(): void
    {
        $this->analyse(
            [__DIR__ . '/../Fixtures/LogRule/ForceDeletesAuditLog.php'],
            [
                [
                    'Logs should not be updated or deleted.',
                    11,
                ],
            ],
        );
    }

    public function testFlagsForceDeleteQuietlyOnLogClass(): void
    {
        $this->analyse(
            [__DIR__ . '/../Fixtures/LogRule/ForceDeletesQuietlyAuditLog.php'],
            [
                [
                    'Logs should not be updated or deleted.',
                    11,
                ],
            ],
        );
    }

    public function testFlagsDestroyOnLogClass(): void
    {
        $this->analyse(
            [__DIR__ . '/../Fixtures/LogRule/DestroysAuditLog.php'],
            [
                [
                    'Logs should not be updated or deleted.',
                    11,
                ],
            ],
        );
    }

    public function testFlagsForceDestroyOnLogClass(): void
    {
        $this->analyse(
            [__DIR__ . '/../Fixtures/LogRule/ForceDestroysAuditLog.php'],
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

    public function testIgnoresForceDeleteOnRegularClass(): void
    {
        $this->analyse(
            [__DIR__ . '/../Fixtures/LogRule/ForceDeletesRegularModel.php'],
            [],
        );
    }

    public function testIgnoresDestroyOnRegularClass(): void
    {
        $this->analyse(
            [__DIR__ . '/../Fixtures/LogRule/DestroysRegularModel.php'],
            [],
        );
    }

    protected function getRule(): Rule
    {
        return new LogRule;
    }
}
