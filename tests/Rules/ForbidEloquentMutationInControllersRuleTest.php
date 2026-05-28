<?php

declare(strict_types = 1);

namespace ScriptDevelopment\PhpstanWarroomRules\Tests\Rules;

use PHPStan\Rules\Rule;
use PHPStan\Testing\RuleTestCase;
use ScriptDevelopment\PhpstanWarroomRules\Rules\ForbidEloquentMutationInControllersRule;

use function sprintf;

/**
 * @extends RuleTestCase<ForbidEloquentMutationInControllersRule>
 */
final class ForbidEloquentMutationInControllersRuleTest extends RuleTestCase
{
    public function testCompliantReadOnlyController(): void
    {
        $this->analyse(
            [__DIR__ . '/../Fixtures/ForbidEloquentMutationInControllers/CompliantReadOnlyController.php'],
            [],
        );
    }

    public function testCompliantActionDelegation(): void
    {
        $this->analyse(
            [__DIR__ . '/../Fixtures/ForbidEloquentMutationInControllers/CompliantActionDelegation.php'],
            [],
        );
    }

    public function testCompliantNonControllerMutation(): void
    {
        $this->analyse(
            [__DIR__ . '/../Fixtures/ForbidEloquentMutationInControllers/CompliantNonControllerMutation.php'],
            [],
        );
    }

    public function testCompliantNonModelReceiver(): void
    {
        $this->analyse(
            [__DIR__ . '/../Fixtures/ForbidEloquentMutationInControllers/CompliantNonModelReceiver.php'],
            [],
        );
    }

    public function testViolationInstanceSave(): void
    {
        $this->analyse(
            [__DIR__ . '/../Fixtures/ForbidEloquentMutationInControllers/ViolationInstanceSave.php'],
            [
                [
                    $this->message('App\Http\Controllers\ViolationInstanceSave', 'save', 'User'),
                    14,
                ],
            ],
        );
    }

    public function testViolationInstanceUpdateArray(): void
    {
        $this->analyse(
            [__DIR__ . '/../Fixtures/ForbidEloquentMutationInControllers/ViolationInstanceUpdateArray.php'],
            [
                [
                    $this->message('App\Http\Controllers\ViolationInstanceUpdateArray', 'update', 'User'),
                    13,
                ],
            ],
        );
    }

    public function testViolationInstanceDelete(): void
    {
        $this->analyse(
            [__DIR__ . '/../Fixtures/ForbidEloquentMutationInControllers/ViolationInstanceDelete.php'],
            [
                [
                    $this->message('App\Http\Controllers\ViolationInstanceDelete', 'delete', 'Post'),
                    13,
                ],
            ],
        );
    }

    public function testViolationInstanceForceDelete(): void
    {
        $this->analyse(
            [__DIR__ . '/../Fixtures/ForbidEloquentMutationInControllers/ViolationInstanceForceDelete.php'],
            [
                [
                    $this->message('App\Http\Controllers\ViolationInstanceForceDelete', 'forceDelete', 'Post'),
                    13,
                ],
            ],
        );
    }

    public function testViolationStaticCreate(): void
    {
        $this->analyse(
            [__DIR__ . '/../Fixtures/ForbidEloquentMutationInControllers/ViolationStaticCreate.php'],
            [
                [
                    $this->message('App\Http\Controllers\ViolationStaticCreate', 'create', 'User'),
                    13,
                ],
            ],
        );
    }

    public function testViolationStaticDestroy(): void
    {
        $this->analyse(
            [__DIR__ . '/../Fixtures/ForbidEloquentMutationInControllers/ViolationStaticDestroy.php'],
            [
                [
                    $this->message('App\Http\Controllers\ViolationStaticDestroy', 'destroy', 'User'),
                    13,
                ],
            ],
        );
    }

    public function testViolationStaticUpdateOrCreate(): void
    {
        $this->analyse(
            [__DIR__ . '/../Fixtures/ForbidEloquentMutationInControllers/ViolationStaticUpdateOrCreate.php'],
            [
                [
                    $this->message('App\Http\Controllers\ViolationStaticUpdateOrCreate', 'updateOrCreate', 'User'),
                    13,
                ],
            ],
        );
    }

    public function testViolationMultipleInOneMethod(): void
    {
        $this->analyse(
            [__DIR__ . '/../Fixtures/ForbidEloquentMutationInControllers/ViolationMultipleInOneMethod.php'],
            [
                [
                    $this->message('App\Http\Controllers\ViolationMultipleInOneMethod', 'save', 'User'),
                    14,
                ],
                [
                    $this->message('App\Http\Controllers\ViolationMultipleInOneMethod', 'delete', 'Post'),
                    15,
                ],
                [
                    $this->message('App\Http\Controllers\ViolationMultipleInOneMethod', 'create', 'User'),
                    16,
                ],
            ],
        );
    }

    public function testViolationKendoCentralSubnamespace(): void
    {
        $this->analyse(
            [__DIR__ . '/../Fixtures/ForbidEloquentMutationInControllers/ViolationKendoCentralSubnamespace.php'],
            [
                [
                    $this->message('App\Http\Controllers\Central\IssueController', 'save', 'Post'),
                    21,
                ],
            ],
        );
    }

    public function testViolationBuilderUpdate(): void
    {
        $this->analyse(
            [__DIR__ . '/../Fixtures/ForbidEloquentMutationInControllers/ViolationBuilderUpdate.php'],
            [
                [
                    $this->message('App\Http\Controllers\ViolationBuilderUpdate', 'update', 'Builder'),
                    19,
                ],
            ],
        );
    }

    protected function getRule(): Rule
    {
        return new ForbidEloquentMutationInControllersRule;
    }

    private function message(string $classFqcn, string $method, string $receiverShortName): string
    {
        return sprintf(
            'Controller %s must not call Eloquent persistence method %s() on %s — delegate to an Action (ADR-0011 + ADR-0019).',
            $classFqcn,
            $method,
            $receiverShortName,
        );
    }
}
