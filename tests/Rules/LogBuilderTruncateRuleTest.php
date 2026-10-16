<?php

declare(strict_types = 1);

namespace ScriptDevelopment\PhpstanWarroomRules\Tests\Rules;

use PHPStan\Rules\Rule;
use PHPStan\Testing\RuleTestCase;
use ScriptDevelopment\PhpstanWarroomRules\Rules\LogBuilderTruncateRule;

/**
 * @extends RuleTestCase<LogBuilderTruncateRule>
 */
final class LogBuilderTruncateRuleTest extends RuleTestCase
{
    public function testFlagsTruncateLogsViaFacade(): void
    {
        $this->analyse(
            [__DIR__ . '/../Fixtures/LogBuilderTruncateRule/TruncatesLogsViaFacade.php'],
            [
                [
                    self::message("Calling truncate() on table 'audit_logs'"),
                    11,
                ],
            ],
        );
    }

    public function testFlagsTruncateLogsViaConnection(): void
    {
        $this->analyse(
            [__DIR__ . '/../Fixtures/LogBuilderTruncateRule/TruncatesLogsViaConnection.php'],
            [
                [
                    self::message("Calling truncate() on table 'logs'"),
                    11,
                ],
            ],
        );
    }

    public function testFlagsTruncateLogsViaInjectedDb(): void
    {
        $this->analyse(
            [__DIR__ . '/../Fixtures/LogBuilderTruncateRule/TruncatesLogsViaInjectedDb.php'],
            [
                [
                    self::message("Calling truncate() on table 'logs'"),
                    19,
                ],
            ],
        );
    }

    public function testIgnoresTruncateLogsViaEloquentFrom(): void
    {
        // Eloquent's `from()` is not recognised — acceptable miss in the same
        // family as variable table names. Receiver-type gate still passes
        // (Eloquent\Builder), chain walk finds no `table()` call.
        $this->analyse(
            [__DIR__ . '/../Fixtures/LogBuilderTruncateRule/TruncatesLogsViaEloquentBuilder.php'],
            [],
        );
    }

    public function testIgnoresTruncateRegularTable(): void
    {
        $this->analyse(
            [__DIR__ . '/../Fixtures/LogBuilderTruncateRule/TruncatesRegularTable.php'],
            [],
        );
    }

    public function testIgnoresTruncateDynamicTable(): void
    {
        $this->analyse(
            [__DIR__ . '/../Fixtures/LogBuilderTruncateRule/TruncatesDynamicTable.php'],
            [],
        );
    }

    public function testIgnoresTruncateUnrelatedReceiver(): void
    {
        $this->analyse(
            [__DIR__ . '/../Fixtures/LogBuilderTruncateRule/TruncatesUnrelatedReceiver.php'],
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
                __DIR__ . '/../Fixtures/CaseFolding/Global.php',
            ],
            [
                [self::message("Calling TRUNCATE() on table 'logs'"), 26],
            ],
        );
    }

    protected function getRule(): Rule
    {
        return new LogBuilderTruncateRule;
    }

    private static function message(string $call): string
    {
        return $call . ' modifies a log. Log and audit records are append-only (ADR-0001): record a new entry instead of changing or removing one. '
            . 'The rule matches "log" anywhere in the table name; if this is not a log, ignore logRule.logModification for this path in phpstan.neon.';
    }
}
