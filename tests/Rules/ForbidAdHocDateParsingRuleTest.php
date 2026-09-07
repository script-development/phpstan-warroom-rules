<?php

declare(strict_types = 1);

namespace ScriptDevelopment\PhpstanWarroomRules\Tests\Rules;

use Carbon\CarbonInterface;
use DateTimeInterface;
use PHPStan\Rules\Rule;
use PHPStan\Testing\RuleTestCase;
use ScriptDevelopment\PhpstanWarroomRules\Rules\ForbidAdHocDateParsingRule;

use function is_subclass_of;
use function sprintf;

/**
 * @extends RuleTestCase<ForbidAdHocDateParsingRule>
 */
final class ForbidAdHocDateParsingRuleTest extends RuleTestCase
{
    private const string DEFAULT_ALLOWED = 'App\Support\Time, App\Support\DateTime, App\Casts';

    private const string CONFIGURED_ALLOWED = 'App\Domain\Clock';

    /**
     * Override hook: when set, `getRule()` returns this instance instead of the
     * default, so one test can reconfigure `dateParsingNamespaces` or pull the
     * rule out of the PHPStan container.
     */
    private ?Rule $ruleOverride = null;

    public function testFlagsParseInsideAnAction(): void
    {
        $this->analyse(
            [__DIR__ . '/../Fixtures/AdHocDateParsing/ParsesInAction.php'],
            [[self::expected('CarbonImmutable::parse()'), 13]],
        );
    }

    public function testFlagsCreateFromFormatInsideAFormRequest(): void
    {
        $this->analyse(
            [__DIR__ . '/../Fixtures/AdHocDateParsing/CreateFromFormatInRequest.php'],
            [[self::expected('Carbon::createFromFormat()'), 16]],
        );
    }

    /**
     * Resolution is through the SCOPE, so an aliased import reports under the
     * class it actually names. A rule matching the written token would both
     * miss this call and report a useless `C::parse()`.
     */
    public function testFlagsAnAliasedCarbonImport(): void
    {
        $this->analyse(
            [__DIR__ . '/../Fixtures/AdHocDateParsing/AliasedCarbon.php'],
            [[self::expected('CarbonImmutable::parse()'), 13]],
        );
    }

    public function testFlagsTheDateFacade(): void
    {
        $this->analyse(
            [__DIR__ . '/../Fixtures/AdHocDateParsing/DateFacade.php'],
            [[self::expected('Date::parse()'), 13]],
        );
    }

    public function testFlagsNativeConstructionWithAnArgument(): void
    {
        $this->analyse(
            [__DIR__ . '/../Fixtures/AdHocDateParsing/NewDateTimeWithArgument.php'],
            [[self::expected('new DateTimeImmutable()'), 13]],
        );
    }

    public function testFlagsStrtotimeInAService(): void
    {
        $this->analyse(
            [__DIR__ . '/../Fixtures/AdHocDateParsing/StrtotimeInService.php'],
            [[self::expected('strtotime()'), 11]],
        );
    }

    public function testIgnoresParsingInsideTheSupportTimeBoundary(): void
    {
        $this->analyse(
            [__DIR__ . '/../Fixtures/AdHocDateParsing/ParsesInSupportTime.php'],
            [],
        );
    }

    public function testIgnoresParsingInsideACast(): void
    {
        $this->analyse(
            [__DIR__ . '/../Fixtures/AdHocDateParsing/ParsesInCast.php'],
            [],
        );
    }

    /**
     * Clock reads, timestamp factories, zero-argument construction, instance
     * calls on an already-decoded value and a non-listed static helper handed
     * a string all sit in a NON-boundary namespace in this fixture, so what
     * holds them back is the method set and the argument gate — not the
     * namespace gate. The string-taking helper is what keeps the method set
     * load-bearing: without it, the argument gate alone would silence every
     * other call here and the name check could be deleted unnoticed.
     */
    public function testIgnoresClockReadsTimestampFactoriesAndInstanceCalls(): void
    {
        $this->analyse(
            [__DIR__ . '/../Fixtures/AdHocDateParsing/NowAndTimestamp.php'],
            [],
        );
    }

    /**
     * A class in the global namespace is outside every configured prefix, so
     * the rule fires. This also pins the `getNamespace() === null` branch, which
     * a prefix-matching loop alone would never reach.
     */
    public function testFlagsParsingInTheGlobalNamespace(): void
    {
        $this->analyse(
            [__DIR__ . '/../Fixtures/AdHocDateParsing/GlobalNamespaceParse.php'],
            [[self::expected('CarbonImmutable::parse()'), 15]],
        );
    }

    /**
     * The DECLINE paths, asserted in a NON-boundary namespace so the namespace
     * gate is not what is keeping them quiet: a dynamic class expression
     * (`$class::parse()`, `new $class()`), a dynamic method name
     * (`CarbonImmutable::{$method}()`), a dynamic function name (`$fn()`), and
     * ordinary calls on a class that is not a date at all. Each is an accepted
     * false negative — the receiver has no resolvable name and guessing one
     * would be a false-positive source a boundary rule cannot afford.
     */
    public function testDeclinesDynamicExpressionsAndUnrelatedCalls(): void
    {
        $this->analyse(
            [__DIR__ . '/../Fixtures/AdHocDateParsing/DynamicAndUnrelatedCalls.php'],
            [],
        );
    }

    /**
     * The argument gate, negative half. Every call here names a method or
     * function the rule lists, in a non-boundary namespace, and none of them
     * receives a string or anything that could be one: integer components,
     * an existing value object, a `null`, or no argument at all. Removing the
     * gate reds this test; so does narrowing it back to a zero-argument check.
     */
    public function testIgnoresComponentFactoriesRewrapsAndZeroArgumentCalls(): void
    {
        $this->analyse(
            [__DIR__ . '/../Fixtures/AdHocDateParsing/ComponentFactoriesAndRewraps.php'],
            [],
        );
    }

    /**
     * The argument gate, positive half and its DIRECTION: a first argument the
     * analyser cannot prove is NOT a string (`mixed`, `int|string`, `?string`)
     * fires. A gate that required a proven string instead would exempt every
     * untyped `$request->input()` parse — this test is what makes that swap
     * red rather than silent.
     */
    public function testFlagsAFirstArgumentThatMayBeAString(): void
    {
        $this->analyse(
            [__DIR__ . '/../Fixtures/AdHocDateParsing/MaybeStringFirstArgument.php'],
            [
                [self::expected('CarbonImmutable::create()'), 19],
                [self::expected('CarbonImmutable::createFromDate()'), 20],
                [self::expected('CarbonImmutable::make()'), 21],
                [self::expected('CarbonImmutable::create()'), 26],
                [self::expected('CarbonImmutable::parse()'), 27],
                [self::expected('new CarbonImmutable()'), 29],
            ],
        );
    }

    public function testIgnoresALocalClassMerelyNamedCarbon(): void
    {
        $this->analyse(
            [__DIR__ . '/../Fixtures/AdHocDateParsing/LocalClassNamedCarbon.php'],
            [],
        );
    }

    /**
     * The denominator assertion. Every entry of both lists appears exactly once
     * in the fixture, so dropping one — by hand or by a mutation operator that
     * removes an array item — fails here at a named line instead of silently
     * narrowing what the rule can see.
     */
    public function testFlagsEveryParsingMethodAndFunction(): void
    {
        $this->analyse(
            [__DIR__ . '/../Fixtures/AdHocDateParsing/AllParsingMethods.php'],
            [
                [self::expected('CarbonImmutable::parse()'), 31],
                [self::expected('CarbonImmutable::rawParse()'), 32],
                [self::expected('CarbonImmutable::createFromFormat()'), 33],
                [self::expected('CarbonImmutable::createFromIsoFormat()'), 34],
                [self::expected('CarbonImmutable::createFromLocaleFormat()'), 35],
                [self::expected('CarbonImmutable::createFromLocaleIsoFormat()'), 36],
                [self::expected('CarbonImmutable::createFromTimeString()'), 37],
                [self::expected('CarbonImmutable::createFromDate()'), 38],
                [self::expected('CarbonImmutable::createFromTime()'), 39],
                [self::expected('CarbonImmutable::create()'), 40],
                [self::expected('CarbonImmutable::make()'), 41],
                [self::expected('CarbonImmutable::createStrict()'), 42],
                [self::expected('CarbonImmutable::createSafe()'), 43],
                [self::expected('strtotime()'), 48],
                [self::expected('date_create()'), 49],
                [self::expected('date_create_immutable()'), 50],
                [self::expected('date_parse()'), 51],
                [self::expected('date_parse_from_format()'), 52],
                [self::expected('date_create_from_format()'), 53],
                [self::expected('date_create_immutable_from_format()'), 54],
                [self::expected('new DateTime()'), 59],
                [self::expected('new DateTimeImmutable()'), 60],
                [self::expected('DateTime::createFromFormat()'), 61],
            ],
        );
    }

    /**
     * Configuration half 1: with `dateParsingNamespaces` pointed elsewhere, the
     * default boundary namespace loses its exemption and every call in it
     * fires. Without this the parameter could be ignored entirely and every
     * other assertion in this file would still pass.
     */
    public function testConfiguredNamespacesReplaceTheDefaultAndExposeIt(): void
    {
        $this->ruleOverride = new ForbidAdHocDateParsingRule(['App\Domain\Clock']);

        $this->analyse(
            [__DIR__ . '/../Fixtures/AdHocDateParsing/ParsesInSupportTime.php'],
            [
                [self::expected('CarbonImmutable::parse()', self::CONFIGURED_ALLOWED), 19],
                [self::expected('CarbonImmutable::createFromFormat()', self::CONFIGURED_ALLOWED), 24],
                [self::expected('new DateTimeImmutable()', self::CONFIGURED_ALLOWED), 29],
                [self::expected('strtotime()', self::CONFIGURED_ALLOWED), 34],
            ],
        );
    }

    /**
     * Configuration half 2: the same override silences the namespace it names.
     * Half 1 alone is satisfied by a rule that ignores the parameter and simply
     * has no exemptions at all.
     */
    public function testConfiguredNamespaceSilencesItsOwnClasses(): void
    {
        $this->ruleOverride = new ForbidAdHocDateParsingRule(['App\Domain\Clock']);

        $this->analyse(
            [__DIR__ . '/../Fixtures/AdHocDateParsing/ParsesInDomainClock.php'],
            [],
        );
    }

    /**
     * Control for the pair above: under the SHIPPED default the very same
     * fixture fires, so half 2's silence is the override talking and not a
     * fixture that could never have been flagged.
     */
    public function testTheConfigurationFixtureFiresUnderTheShippedDefault(): void
    {
        $this->analyse(
            [__DIR__ . '/../Fixtures/AdHocDateParsing/ParsesInDomainClock.php'],
            [[self::expected('CarbonImmutable::parse()'), 19]],
        );
    }

    /**
     * End-to-end pin on the `extension.neon` registration path consumers
     * actually use: resolve the rule from the PHPStan container so the shipped
     * `dateParsingNamespaces` default and the `%dateParsingNamespaces%` argument
     * wiring are exercised, NOT the PHP constructor default. A NEON quoting
     * regression in the shipped list silently un-exempts every boundary
     * namespace for every default consumer; this catches it by asserting the
     * boundary fixture is still silent while a non-boundary one still fires.
     */
    public function testRuleResolvesFromExtensionNeonWithTheShippedDefault(): void
    {
        $this->ruleOverride = self::getContainer()->getByType(ForbidAdHocDateParsingRule::class);

        $this->analyse(
            [
                __DIR__ . '/../Fixtures/AdHocDateParsing/ParsesInSupportTime.php',
                __DIR__ . '/../Fixtures/AdHocDateParsing/ParsesInAction.php',
            ],
            [[self::expected('CarbonImmutable::parse()'), 13]],
        );
    }

    /**
     * The rule anchors on `DateTimeInterface` alone and relies on
     * `CarbonInterface` extending it to cover the whole Carbon family. That is
     * a fact about an upstream package, not about this code: a Carbon release
     * that stopped extending it would narrow the rule to the two PHP natives
     * with every fixture above still green, because each of them names a Carbon
     * class the analyser would then simply not recognise. Asserting it here is
     * what turns that into a red build.
     */
    public function testCarbonInterfaceExtendsTheDateTimeAnchor(): void
    {
        self::assertTrue(
            is_subclass_of(CarbonInterface::class, DateTimeInterface::class),
            'Carbon\CarbonInterface no longer extends DateTimeInterface, so ForbidAdHocDateParsingRule::DATE_TIME_ANCHOR no longer covers the Carbon family. Add an explicit Carbon anchor to the rule.',
        );
    }

    /**
     * Load the shipped `extension.neon` so the container-resolution test can
     * pull the rule out with its NEON-configured parameter applied.
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
        return $this->ruleOverride ?? new ForbidAdHocDateParsingRule;
    }

    private static function expected(string $call, string $allowed = self::DEFAULT_ALLOWED): string
    {
        return sprintf(
            'Ad-hoc date parsing (%s) outside %s: decode the string once at the boundary and pass the value object (ADR-0020 Amd 1).',
            $call,
            $allowed,
        );
    }
}
