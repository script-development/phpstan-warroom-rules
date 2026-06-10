<?php

declare(strict_types = 1);

namespace ScriptDevelopment\PhpstanWarroomRules\Tests\Rules;

use PHPStan\Rules\Rule;
use PHPStan\Testing\RuleTestCase;
use ScriptDevelopment\PhpstanWarroomRules\Rules\EnforceCurrentUserAttributeRule;

/**
 * @extends RuleTestCase<EnforceCurrentUserAttributeRule>
 */
final class EnforceCurrentUserAttributeRuleTest extends RuleTestCase
{
    private const string EXPECTED_REQUEST_USER = 'Authenticated-user resolution in controller methods uses the #[CurrentUser] container attribute. Add `#[\Illuminate\Container\Attributes\CurrentUser] User $user` to the method signature instead of calling $request->user() inside the body.';

    private const string EXPECTED_AUTH_FACADE = 'Authenticated-user resolution in controller methods uses the #[CurrentUser] container attribute. Add `#[\Illuminate\Container\Attributes\CurrentUser] User $user` to the method signature instead of calling Auth::user() inside the body.';

    private const string EXPECTED_AUTH_HELPER = 'Authenticated-user resolution in controller methods uses the #[CurrentUser] container attribute. Add `#[\Illuminate\Container\Attributes\CurrentUser] User $user` to the method signature instead of calling auth()->user() inside the body.';

    public function testFlagsRequestUserInController(): void
    {
        $this->analyse(
            [
                __DIR__ . '/../Fixtures/CurrentUserAttribute/_stubs.php',
                __DIR__ . '/../Fixtures/CurrentUserAttribute/RequestUserInController.php',
            ],
            [
                [self::EXPECTED_REQUEST_USER, 14],
            ],
        );
    }

    public function testFlagsAuthFacadeInController(): void
    {
        $this->analyse(
            [
                __DIR__ . '/../Fixtures/CurrentUserAttribute/_stubs.php',
                __DIR__ . '/../Fixtures/CurrentUserAttribute/AuthFacadeInController.php',
            ],
            [
                [self::EXPECTED_AUTH_FACADE, 14],
            ],
        );
    }

    public function testFlagsAuthHelperInController(): void
    {
        $this->analyse(
            [
                __DIR__ . '/../Fixtures/CurrentUserAttribute/_stubs.php',
                __DIR__ . '/../Fixtures/CurrentUserAttribute/AuthHelperInController.php',
            ],
            [
                [self::EXPECTED_AUTH_HELPER, 13],
            ],
        );
    }

    public function testFlagsRequestUserInControllerWithAssertOnceOnTheUserCall(): void
    {
        // The exact PR #263 shape — `$user = $request->user(); assert($user instanceof User);`
        // fires once on the `$request->user()` call. The downstream assert is noise the rule
        // does not care about.
        $this->analyse(
            [
                __DIR__ . '/../Fixtures/CurrentUserAttribute/_stubs.php',
                __DIR__ . '/../Fixtures/CurrentUserAttribute/RequestUserInControllerWithAssert.php',
            ],
            [
                [self::EXPECTED_REQUEST_USER, 19],
            ],
        );
    }

    public function testFlagsRequestUserInBaselessFinalController(): void
    {
        // Regression proof for the namespace-gate fix (PR #26 blocker). A
        // base-less `final` controller with NO `extends Controller` — the exact
        // shape kendo / ublgenie / entreezuil ship. The prior ancestry gate
        // (`isSubclassOf(Controller)`) matched zero such classes, so the rule
        // was a silent no-op. The namespace gate must flag this.
        $this->analyse(
            [
                __DIR__ . '/../Fixtures/CurrentUserAttribute/_stubs.php',
                __DIR__ . '/../Fixtures/CurrentUserAttribute/RequestUserInBaselessController.php',
            ],
            [
                [self::EXPECTED_REQUEST_USER, 18],
            ],
        );
    }

    public function testIgnoresCurrentUserAttributeInBaselessFinalController(): void
    {
        // Compliant base-less `final` controller — container-attribute injection,
        // no body call. The rule must not fire even though the namespace gate
        // now matches the class.
        $this->analyse(
            [
                __DIR__ . '/../Fixtures/CurrentUserAttribute/_stubs.php',
                __DIR__ . '/../Fixtures/CurrentUserAttribute/CurrentUserAttributeInBaselessController.php',
            ],
            [],
        );
    }

    public function testIgnoresCurrentUserAttribute(): void
    {
        $this->analyse(
            [
                __DIR__ . '/../Fixtures/CurrentUserAttribute/_stubs.php',
                __DIR__ . '/../Fixtures/CurrentUserAttribute/UsesCurrentUserAttribute.php',
            ],
            [],
        );
    }

    public function testIgnoresFormRequestUserResolution(): void
    {
        $this->analyse(
            [
                __DIR__ . '/../Fixtures/CurrentUserAttribute/_stubs.php',
                __DIR__ . '/../Fixtures/CurrentUserAttribute/RequestUserInFormRequest.php',
            ],
            [],
        );
    }

    public function testIgnoresMiddlewareUserResolution(): void
    {
        $this->analyse(
            [
                __DIR__ . '/../Fixtures/CurrentUserAttribute/_stubs.php',
                __DIR__ . '/../Fixtures/CurrentUserAttribute/AuthInMiddleware.php',
            ],
            [],
        );
    }

    public function testIgnoresActionUserResolution(): void
    {
        $this->analyse(
            [
                __DIR__ . '/../Fixtures/CurrentUserAttribute/_stubs.php',
                __DIR__ . '/../Fixtures/CurrentUserAttribute/AuthInAction.php',
            ],
            [],
        );
    }

    public function testIgnoresJobUserResolution(): void
    {
        $this->analyse(
            [
                __DIR__ . '/../Fixtures/CurrentUserAttribute/_stubs.php',
                __DIR__ . '/../Fixtures/CurrentUserAttribute/AuthInJob.php',
            ],
            [],
        );
    }

    public function testIgnoresTopLevelCallOutsideAnyClass(): void
    {
        // Top-level call outside any namespace — `$scope->getNamespace()`
        // returns null, the null-namespace gate must short-circuit.
        // Kills the FalseValue mutant on `insideControllerMethod()`'s
        // `getNamespace() === null` branch.
        $this->analyse(
            [
                __DIR__ . '/../Fixtures/CurrentUserAttribute/_stubs.php',
                __DIR__ . '/../Fixtures/CurrentUserAttribute/TopLevelAuthCall.php',
            ],
            [],
        );
    }

    protected function getRule(): Rule
    {
        return new EnforceCurrentUserAttributeRule;
    }
}
