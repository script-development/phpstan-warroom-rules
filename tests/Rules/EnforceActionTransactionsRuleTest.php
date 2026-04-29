<?php

declare(strict_types = 1);

namespace ScriptDevelopment\PhpstanWarroomRules\Tests\Rules;

use PHPStan\Rules\Rule;
use PHPStan\Testing\RuleTestCase;
use ScriptDevelopment\PhpstanWarroomRules\Rules\EnforceActionTransactionsRule;

/**
 * @extends RuleTestCase<EnforceActionTransactionsRule>
 *
 * NOTE: Coverage is currently a smoke check on the simplest cases. The full
 * matrix (non-database property exclusions, transaction detection in nested
 * closures, write-method coverage across the 18-entry list) lands in the
 * follow-up test-expansion PR — see CHANGELOG.md [Unreleased].
 */
final class EnforceActionTransactionsRuleTest extends RuleTestCase
{
    public function testFlagsActionWithMultipleWritesAndNoTransaction(): void
    {
        $this->analyse(
            [__DIR__ . '/../Fixtures/ActionTransactions/MultipleWritesNoTransaction.php'],
            [
                [
                    'Action has 2 write operations without a database transaction. Inject ConnectionInterface and wrap writes in $this->connection->transaction().',
                    15,
                ],
            ],
        );
    }

    public function testIgnoresActionWithMultipleWritesInsideTransaction(): void
    {
        $this->analyse(
            [__DIR__ . '/../Fixtures/ActionTransactions/MultipleWritesInsideTransaction.php'],
            [],
        );
    }

    public function testIgnoresActionWithSingleWrite(): void
    {
        $this->analyse(
            [__DIR__ . '/../Fixtures/ActionTransactions/SingleWrite.php'],
            [],
        );
    }

    protected function getRule(): Rule
    {
        return new EnforceActionTransactionsRule;
    }
}
