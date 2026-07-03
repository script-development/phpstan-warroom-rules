<?php

declare(strict_types = 1);

namespace ScriptDevelopment\PhpstanWarroomRules\Tests\Rules;

use PHPStan\Rules\Rule;
use PHPStan\Testing\RuleTestCase;
use ScriptDevelopment\PhpstanWarroomRules\Rules\ForbidResourceWrappedInJsonResponseRule;

/**
 * @extends RuleTestCase<ForbidResourceWrappedInJsonResponseRule>
 */
final class ForbidResourceWrappedInJsonResponseRuleTest extends RuleTestCase
{
    private const string MESSAGE = 'Controllers must not wrap a JsonResource in response()->json(...) / new JsonResponse(...) — a resource is already a Responsable. '
        . 'Return the resource directly: return XxxResource::fromModel($model);';

    /**
     * Override hook: when set, `getRule()` returns this instance instead of
     * the default. Lets a single test reconfigure the
     * `controllerNamespacePrefixes` parameter.
     */
    private ?Rule $ruleOverride = null;

    public function testFlagsResourceInResponseJson(): void
    {
        $this->analyse(
            [__DIR__ . '/../Fixtures/ResourceWrappedInJsonResponse/WrapsResourceInResponseJson.php'],
            [
                [self::MESSAGE, 14],
            ],
        );
    }

    public function testFlagsResourceInNewJsonResponse(): void
    {
        $this->analyse(
            [__DIR__ . '/../Fixtures/ResourceWrappedInJsonResponse/WrapsResourceInNewJsonResponse.php'],
            [
                [self::MESSAGE, 14],
            ],
        );
    }

    public function testIgnoresResourceReturnedDirectly(): void
    {
        $this->analyse(
            [__DIR__ . '/../Fixtures/ResourceWrappedInJsonResponse/CompliantReturnsResourceDirectly.php'],
            [],
        );
    }

    public function testIgnoresArrayDtoScalarAndNullPayloads(): void
    {
        $this->analyse(
            [__DIR__ . '/../Fixtures/ResourceWrappedInJsonResponse/CompliantWrapsArrayAndDtoAndNull.php'],
            [],
        );
    }

    public function testIgnoresResourceNestedInNamedEnvelope(): void
    {
        $this->analyse(
            [__DIR__ . '/../Fixtures/ResourceWrappedInJsonResponse/CompliantNamedEnvelope.php'],
            [],
        );
    }

    public function testIgnoresResourceWrappedOutsideControllers(): void
    {
        $this->analyse(
            [__DIR__ . '/../Fixtures/ResourceWrappedInJsonResponse/ResourceWrappedOutsideControllers.php'],
            [],
        );
    }

    public function testSubNamespacedControllerIsCleanUnderDefaultConfig(): void
    {
        // emmie's `App\Http\Client\Controllers` namespace does NOT start with
        // the default `App\Http\Controllers` prefix, so the resource-wrap is
        // invisible to the default gate — no error. This pins the "zero
        // behaviour change at the default" invariant: the sub-namespace stays
        // out of scope unless a consumer opts it in.
        $this->analyse(
            [__DIR__ . '/../Fixtures/ResourceWrappedInJsonResponse/SubNamespacedClientController.php'],
            [],
        );
    }

    public function testSubNamespacedControllerFlaggedWhenPrefixConfigured(): void
    {
        // Re-run the same fixture with the sub-namespace added to
        // `controllerNamespacePrefixes` — the resource-wrap must now fire.
        // Proves the parameter brings a divergent controller namespace into
        // scope (the emmie opt-in path).
        $this->ruleOverride = new ForbidResourceWrappedInJsonResponseRule(
            ['App\Http\Controllers', 'App\Http\Client\Controllers'],
        );

        $this->analyse(
            [__DIR__ . '/../Fixtures/ResourceWrappedInJsonResponse/SubNamespacedClientController.php'],
            [
                [self::MESSAGE, 20],
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
        // default would silently no-op the rule for every default consumer;
        // this gate catches it by asserting a canonical `App\Http\Controllers`
        // resource-wrap still flags under the shipped default.
        $this->ruleOverride = self::getContainer()->getByType(ForbidResourceWrappedInJsonResponseRule::class);

        $this->analyse(
            [__DIR__ . '/../Fixtures/ResourceWrappedInJsonResponse/WrapsResourceInResponseJson.php'],
            [
                [self::MESSAGE, 14],
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
        return $this->ruleOverride ?? new ForbidResourceWrappedInJsonResponseRule;
    }
}
