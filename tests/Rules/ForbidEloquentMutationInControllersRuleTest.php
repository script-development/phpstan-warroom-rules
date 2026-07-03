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
    /**
     * Override hook: when set, `getRule()` returns this instance instead of
     * the default. Lets a single test reconfigure the
     * `controllerNamespacePrefixes` parameter.
     */
    private ?Rule $ruleOverride = null;

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

    public function testSubNamespacedControllerIsCleanUnderDefaultConfig(): void
    {
        // emmie's `App\Http\Client\Controllers` namespace does NOT start with
        // the default `App\Http\Controllers` prefix, so the Eloquent mutation
        // is invisible to the default gate — no error. This pins the
        // "zero behaviour change at the default" invariant: the sub-namespace
        // stays out of scope unless a consumer opts it in.
        $this->analyse(
            [
                __DIR__ . '/../Fixtures/ForbidEloquentMutationInControllers/_stubs.php',
                __DIR__ . '/../Fixtures/ForbidEloquentMutationInControllers/SubNamespacedClientController.php',
            ],
            [],
        );
    }

    public function testSubNamespacedControllerFlaggedWhenPrefixConfigured(): void
    {
        // Re-run the same fixture with the sub-namespace added to
        // `controllerNamespacePrefixes` — the mutation must now fire. Proves
        // the parameter brings a divergent controller namespace into scope
        // (the emmie opt-in path).
        $this->ruleOverride = new ForbidEloquentMutationInControllersRule(
            ['App\Http\Controllers', 'App\Http\Client\Controllers'],
        );

        $this->analyse(
            [
                __DIR__ . '/../Fixtures/ForbidEloquentMutationInControllers/_stubs.php',
                __DIR__ . '/../Fixtures/ForbidEloquentMutationInControllers/SubNamespacedClientController.php',
            ],
            [
                [
                    $this->message('App\Http\Client\Controllers\SubNamespacedClientController', 'save', 'User'),
                    22,
                ],
            ],
        );
    }

    public function testRuleResolvesFromExtensionNeonAndFiresOnDefaultPrefix(): void
    {
        // End-to-end pin on the extension.neon registration path consumers
        // actually use: resolve the rule from the PHPStan container so the
        // shipped `controllerNamespacePrefixes` default and the
        // `%controllerNamespacePrefixes%` argument wiring are exercised — NOT
        // the PHP constructor default. A NEON quoting regression in the shipped
        // default (e.g. the double-backslash single-quoted form) would silently
        // no-op the rule for every default consumer; this gate catches it by
        // asserting the kendo `App\Http\Controllers\Central\*` sub-namespace
        // still flags under the shipped default.
        $this->ruleOverride = self::getContainer()->getByType(ForbidEloquentMutationInControllersRule::class);

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

    /**
     * Load the shipped extension.neon so testRuleResolvesFromExtensionNeonAndFires*
     * can pull the rule out of the container with its NEON-configured
     * `controllerNamespacePrefixes` parameter applied.
     *
     * @return array<int, string>
     */
    public static function getAdditionalConfigFiles(): array
    {
        return [
            __DIR__ . '/../../extension.neon',
        ];
    }

    protected function getRule(): Rule
    {
        return $this->ruleOverride ?? new ForbidEloquentMutationInControllersRule;
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
