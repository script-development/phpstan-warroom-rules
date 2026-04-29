<?php

declare(strict_types = 1);

namespace ScriptDevelopment\PhpstanWarroomRules\Tests\Rules;

use PHPStan\Rules\Rule;
use PHPStan\Testing\RuleTestCase;
use ScriptDevelopment\PhpstanWarroomRules\Rules\ForbidDatabaseManagerInActionsRule;

/**
 * @extends RuleTestCase<ForbidDatabaseManagerInActionsRule>
 */
final class ForbidDatabaseManagerInActionsRuleTest extends RuleTestCase
{
    public function testFlagsDatabaseManagerInjection(): void
    {
        $this->analyse(
            [__DIR__ . '/../Fixtures/DatabaseManagerInAction/InjectsDatabaseManager.php'],
            [
                [
                    'Actions must inject ConnectionInterface instead of DatabaseManager.',
                    12,
                ],
            ],
        );
    }

    public function testIgnoresConnectionInterfaceInjection(): void
    {
        $this->analyse(
            [__DIR__ . '/../Fixtures/DatabaseManagerInAction/InjectsConnectionInterface.php'],
            [],
        );
    }

    public function testIgnoresClassesOutsideAppActions(): void
    {
        $this->analyse(
            [__DIR__ . '/../Fixtures/DatabaseManagerInAction/NonActionWithDatabaseManager.php'],
            [],
        );
    }

    protected function getRule(): Rule
    {
        return new ForbidDatabaseManagerInActionsRule;
    }
}
