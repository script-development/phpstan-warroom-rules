<?php

declare(strict_types = 1);

namespace ScriptDevelopment\PhpstanWarroomRules\Tests\Rules;

use PHPStan\Rules\Rule;
use PHPStan\Testing\RuleTestCase;
use ScriptDevelopment\PhpstanWarroomRules\Rules\ForbidInlineArrayJsonResponseInControllersRule;

/**
 * @extends RuleTestCase<ForbidInlineArrayJsonResponseInControllersRule>
 */
final class ForbidInlineArrayJsonResponseInControllersRuleTest extends RuleTestCase
{
    private const string MESSAGE = 'Controllers must not construct JsonResponse with an array payload — '
        . 'return a Resource/ResourceData or a dedicated JsonResponse subclass (ADR-0009).';

    /**
     * Override hook: when set, `getRule()` returns this instance instead of the
     * default. Lets a single test reconfigure `controllerNamespacePrefixes`.
     */
    private ?Rule $ruleOverride = null;

    public function testFlagsInlineArrayInNewJsonResponse(): void
    {
        $this->analyse(
            [
                __DIR__ . '/../Fixtures/InlineArrayJsonResponse/_stubs.php',
                __DIR__ . '/../Fixtures/InlineArrayJsonResponse/InlineArrayNewJsonResponse.php',
            ],
            [
                [self::MESSAGE, 14],
            ],
        );
    }

    public function testFlagsInlineArrayInResponseJson(): void
    {
        $this->analyse(
            [
                __DIR__ . '/../Fixtures/InlineArrayJsonResponse/_stubs.php',
                __DIR__ . '/../Fixtures/InlineArrayJsonResponse/InlineArrayResponseJson.php',
            ],
            [
                [self::MESSAGE, 16],
            ],
        );
    }

    public function testFlagsArrayTypedVariablePayload(): void
    {
        $this->analyse(
            [
                __DIR__ . '/../Fixtures/InlineArrayJsonResponse/_stubs.php',
                __DIR__ . '/../Fixtures/InlineArrayJsonResponse/ArrayVariableNewJsonResponse.php',
            ],
            [
                [self::MESSAGE, 18],
            ],
        );
    }

    public function testIgnoresResourcePayload(): void
    {
        $this->analyse(
            [
                __DIR__ . '/../Fixtures/InlineArrayJsonResponse/_stubs.php',
                __DIR__ . '/../Fixtures/InlineArrayJsonResponse/ResourcePayloadJsonResponse.php',
            ],
            [],
        );
    }

    public function testIgnoresJsonResponseSubclassWithArrayPayload(): void
    {
        $this->analyse(
            [
                __DIR__ . '/../Fixtures/InlineArrayJsonResponse/_stubs.php',
                __DIR__ . '/../Fixtures/InlineArrayJsonResponse/SubclassArrayPayload.php',
            ],
            [],
        );
    }

    public function testIgnoresNoArgsAndNullPayload(): void
    {
        $this->analyse(
            [
                __DIR__ . '/../Fixtures/InlineArrayJsonResponse/_stubs.php',
                __DIR__ . '/../Fixtures/InlineArrayJsonResponse/NoArgsAndNullPayload.php',
            ],
            [],
        );
    }

    public function testIgnoresNonJsonResponseFactoryMethod(): void
    {
        // `response()->make([...])` — a non-json factory method. The rule
        // matches only `response()->json(...)`, so an array argument here stays
        // silent (method-name specificity of the AST-shape gate).
        $this->analyse(
            [
                __DIR__ . '/../Fixtures/InlineArrayJsonResponse/_stubs.php',
                __DIR__ . '/../Fixtures/InlineArrayJsonResponse/NonJsonResponseFactoryMethod.php',
            ],
            [],
        );
    }

    public function testIgnoresArrayPayloadOutsideControllers(): void
    {
        $this->analyse(
            [
                __DIR__ . '/../Fixtures/InlineArrayJsonResponse/_stubs.php',
                __DIR__ . '/../Fixtures/InlineArrayJsonResponse/ArrayPayloadOutsideControllers.php',
            ],
            [],
        );
    }

    public function testSubNamespacedControllerIsCleanUnderDefaultConfig(): void
    {
        // emmie's `App\Http\Client\Controllers` namespace does NOT start with
        // the default `App\Http\Controllers` prefix — the inline-array response
        // is invisible to the default gate. Pins the "zero behaviour change at
        // the default" invariant.
        $this->analyse(
            [
                __DIR__ . '/../Fixtures/InlineArrayJsonResponse/_stubs.php',
                __DIR__ . '/../Fixtures/InlineArrayJsonResponse/SubNamespacedClientController.php',
            ],
            [],
        );
    }

    public function testSubNamespacedControllerFlaggedWhenPrefixConfigured(): void
    {
        // Re-run the same fixture with the sub-namespace added to
        // `controllerNamespacePrefixes` — the inline-array response must now
        // fire. Proves the parameter brings a divergent controller namespace
        // into scope (the emmie opt-in path).
        $this->ruleOverride = new ForbidInlineArrayJsonResponseInControllersRule(
            ['App\Http\Controllers', 'App\Http\Client\Controllers'],
        );

        $this->analyse(
            [
                __DIR__ . '/../Fixtures/InlineArrayJsonResponse/_stubs.php',
                __DIR__ . '/../Fixtures/InlineArrayJsonResponse/SubNamespacedClientController.php',
            ],
            [
                [self::MESSAGE, 19],
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
        // inline-array response still flags under the shipped default.
        $this->ruleOverride = self::getContainer()->getByType(ForbidInlineArrayJsonResponseInControllersRule::class);

        $this->analyse(
            [
                __DIR__ . '/../Fixtures/InlineArrayJsonResponse/_stubs.php',
                __DIR__ . '/../Fixtures/InlineArrayJsonResponse/InlineArrayNewJsonResponse.php',
            ],
            [
                [self::MESSAGE, 14],
            ],
        );
    }

    /**
     * Load the shipped extension.neon so the container-resolved test can pull
     * the rule out with its NEON-configured `controllerNamespacePrefixes`.
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
        return $this->ruleOverride ?? new ForbidInlineArrayJsonResponseInControllersRule;
    }
}
