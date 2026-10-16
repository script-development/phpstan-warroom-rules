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
                    self::message('Calling update() on App\Models\AuditLog'),
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
                    self::message('Calling delete() on App\Models\AuditLog'),
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
                    self::message('Calling forceDelete() on App\Models\AuditLog'),
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
                    self::message('Calling forceDeleteQuietly() on App\Models\AuditLog'),
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
                    self::message('Calling destroy() on App\Models\AuditLog'),
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
                    self::message('Calling forceDestroy() on App\Models\AuditLog'),
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

    /**
     * WR-1291. PHP dispatches method names case-insensitively, so an upper- or
     * mixed-case call is read as its lower-case spelling.
     */
    public function testReadsAMethodNameRegardlessOfCase(): void
    {
        $this->analyse(
            [
                __DIR__ . '/../Fixtures/LogRule/AuditLog.php',
                __DIR__ . '/../Fixtures/CaseFolding/Global.php',
            ],
            [
                [self::message('Calling DELETE() on App\Models\AuditLog'), 16],
                [self::message('Calling Destroy() on App\Models\AuditLog'), 21],
            ],
        );
    }

    protected function getRule(): Rule
    {
        return new LogRule;
    }

    private static function message(string $call): string
    {
        return $call . ' modifies a log. Log and audit records are append-only (ADR-0001): record a new entry instead of changing or removing one. '
            . 'The rule matches "log" anywhere in the class name; if this is not a log, ignore logRule.logModification for this path in phpstan.neon.';
    }
}
