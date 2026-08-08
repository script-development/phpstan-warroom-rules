<?php

declare(strict_types = 1);

namespace ScriptDevelopment\PhpstanWarroomRules\Tests\Rules;

use PHPStan\Rules\Rule;
use PHPStan\Testing\RuleTestCase;
use ScriptDevelopment\PhpstanWarroomRules\Rules\ForbidSentinelFallbackOnNarrowingHelperRule;

use function sprintf;

/**
 * @extends RuleTestCase<ForbidSentinelFallbackOnNarrowingHelperRule>
 */
final class ForbidSentinelFallbackOnNarrowingHelperRuleTest extends RuleTestCase
{
    private const string MESSAGE = 'Narrowing helper %s() returns null for unreadable input; the `%s %s` fallback hides that failure '
        . 'by turning it into a plausible value that gets persisted or unlocks a branch. '
        . 'Skip the write, fail closed, or handle null explicitly (`?? null` preserves the failure signal).';

    private const string STUBS = __DIR__ . '/../Fixtures/SentinelFallbackOnNarrowingHelper/_stubs.php';

    private const string READER_TEXT = 'App\Support\LeafReader::text';

    /**
     * Override hook: when set, `getRule()` returns this instance instead of the
     * default. Lets a single test reconfigure `narrowingHelperNamespacePrefixes`
     * or pull the rule out of the NEON-configured container.
     */
    private ?Rule $ruleOverride = null;

    public function testFlagsEmptyStringFallbackOnMethodCall(): void
    {
        // The tc-api #360 shape — `?? ''` on a nullable-scalar boundary helper.
        $this->analyse(
            [self::STUBS, __DIR__ . '/../Fixtures/SentinelFallbackOnNarrowingHelper/MethodCallCoalesceEmptyString.php'],
            [[sprintf(self::MESSAGE, self::READER_TEXT, '??', "''"), 19]],
        );
    }

    public function testFlagsZeroFallbackOnMethodCall(): void
    {
        $this->analyse(
            [self::STUBS, __DIR__ . '/../Fixtures/SentinelFallbackOnNarrowingHelper/MethodCallCoalesceZero.php'],
            [[sprintf(self::MESSAGE, 'App\Support\LeafReader::number', '??', '0'), 18]],
        );
    }

    public function testFlagsShortTernaryFallback(): void
    {
        $this->analyse(
            [self::STUBS, __DIR__ . '/../Fixtures/SentinelFallbackOnNarrowingHelper/ShortTernaryUnknown.php'],
            [[sprintf(self::MESSAGE, self::READER_TEXT, '?:', "'unknown'"), 18]],
        );
    }

    public function testFlagsStaticCallFallback(): void
    {
        $this->analyse(
            [self::STUBS, __DIR__ . '/../Fixtures/SentinelFallbackOnNarrowingHelper/StaticCallCoalesce.php'],
            [[sprintf(self::MESSAGE, 'App\Support\LeafReader::staticText', '??', "''"), 14]],
        );
    }

    public function testFlagsPlainFirstPartyFunctionFallback(): void
    {
        $this->analyse(
            [__DIR__ . '/../Fixtures/SentinelFallbackOnNarrowingHelper/PlainFunctionCoalesce.php'],
            [[sprintf(self::MESSAGE, 'App\Support\leafText', '??', "''"), 21]],
        );
    }

    public function testFlagsClassConstantEmptyArrayAndBooleanSentinels(): void
    {
        // The non-string sentinel shapes: a class constant, `[]`, and `false`.
        $this->analyse(
            [self::STUBS, __DIR__ . '/../Fixtures/SentinelFallbackOnNarrowingHelper/SentinelShapes.php'],
            [
                // PHPStan resolves the `Name` node, so the constant renders
                // fully qualified even though the source writes `LeafReader::`.
                [sprintf(self::MESSAGE, self::READER_TEXT, '??', 'App\Support\LeafReader::UNKNOWN'), 18],
                [sprintf(self::MESSAGE, 'App\Support\LeafReader::scalar', '??', '[]'), 27],
                [sprintf(self::MESSAGE, 'App\Support\LeafReader::flag', '??', 'false'), 33],
            ],
        );
    }

    public function testIgnoresNullFallback(): void
    {
        // `?? null` preserves the failure signal — the remediation, not the violation.
        $this->analyse(
            [self::STUBS, __DIR__ . '/../Fixtures/SentinelFallbackOnNarrowingHelper/NullFallback.php'],
            [],
        );
    }

    public function testIgnoresHelperWithoutMixedParameter(): void
    {
        $this->analyse(
            [self::STUBS, __DIR__ . '/../Fixtures/SentinelFallbackOnNarrowingHelper/NarrowedParameterHelper.php'],
            [],
        );
    }

    public function testIgnoresHelperWithNonNullableReturn(): void
    {
        $this->analyse(
            [self::STUBS, __DIR__ . '/../Fixtures/SentinelFallbackOnNarrowingHelper/NonNullableReturnHelper.php'],
            [],
        );
    }

    public function testIgnoresHelperWithNonScalarNullableReturn(): void
    {
        $this->analyse(
            [self::STUBS, __DIR__ . '/../Fixtures/SentinelFallbackOnNarrowingHelper/NonScalarReturnHelper.php'],
            [],
        );
    }

    public function testIgnoresVendorFunction(): void
    {
        // `filter_var(...) ?? ''` — a builtin is outside the first-party namespaces.
        $this->analyse(
            [__DIR__ . '/../Fixtures/SentinelFallbackOnNarrowingHelper/VendorFunctionFallback.php'],
            [],
        );
    }

    public function testIgnoresPlainPropertyAndArrayAccessFallback(): void
    {
        $this->analyse(
            [__DIR__ . '/../Fixtures/SentinelFallbackOnNarrowingHelper/PlainAccessFallback.php'],
            [],
        );
    }

    public function testIgnoresNonLiteralFallback(): void
    {
        // A variable / call fallback is deliberately left alone (FP-prone half).
        $this->analyse(
            [self::STUBS, __DIR__ . '/../Fixtures/SentinelFallbackOnNarrowingHelper/NonLiteralFallback.php'],
            [],
        );
    }

    public function testIgnoresHelperOutsideConfiguredNamespace(): void
    {
        // `Application\Support` must not match the `App\` default — the prefix
        // comparison is namespace-boundary aware, not a bare string prefix.
        $this->analyse(
            [self::STUBS, __DIR__ . '/../Fixtures/SentinelFallbackOnNarrowingHelper/ForeignNamespaceHelper.php'],
            [],
        );
    }

    public function testFlagsHelperInAdditionallyConfiguredNamespace(): void
    {
        // Same fixture, prefixes reconfigured — pins that the namespace gate is
        // the only thing keeping it silent above.
        $this->ruleOverride = new ForbidSentinelFallbackOnNarrowingHelperRule(
            $this->createReflectionProvider(),
            ['Application\Support'],
        );

        $this->analyse(
            [self::STUBS, __DIR__ . '/../Fixtures/SentinelFallbackOnNarrowingHelper/ForeignNamespaceHelper.php'],
            [[sprintf(self::MESSAGE, 'Application\Support\ImposterReader::text', '??', "''"), 19]],
        );
    }

    public function testRuleResolvesFromExtensionNeonAndFires(): void
    {
        // Container-resolved: exercises the SHIPPED
        // `narrowingHelperNamespacePrefixes` default and its `%param%` wiring,
        // not the PHP constructor default. A NEON quoting regression silently
        // no-ops the rule while every direct-instantiation test stays green;
        // this is the only gate that catches it.
        $this->ruleOverride = self::getContainer()->getByType(ForbidSentinelFallbackOnNarrowingHelperRule::class);

        $this->analyse(
            [self::STUBS, __DIR__ . '/../Fixtures/SentinelFallbackOnNarrowingHelper/MethodCallCoalesceEmptyString.php'],
            [[sprintf(self::MESSAGE, self::READER_TEXT, '??', "''"), 19]],
        );
    }

    /**
     * Load the shipped extension.neon so testRuleResolvesFromExtensionNeonAndFires
     * can pull the rule out of the container with its NEON-configured
     * `narrowingHelperNamespacePrefixes` parameter applied.
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
        return $this->ruleOverride ?? new ForbidSentinelFallbackOnNarrowingHelperRule($this->createReflectionProvider());
    }
}
