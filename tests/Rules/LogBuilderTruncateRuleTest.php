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
                    'Logs should not be updated or deleted.',
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
                    'Logs should not be updated or deleted.',
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
                    'Logs should not be updated or deleted.',
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

    protected function getRule(): Rule
    {
        return new LogBuilderTruncateRule;
    }
}
