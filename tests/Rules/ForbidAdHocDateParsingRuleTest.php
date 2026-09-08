<?php

declare(strict_types = 1);

namespace ScriptDevelopment\PhpstanWarroomRules\Tests\Rules;

use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use DateTime;
use DateTimeImmutable;
use DateTimeInterface;
use PhpParser\Node\Expr\CallLike;
use PHPStan\Node\FunctionCallableNode;
use PHPStan\Node\MethodCallableNode;
use PHPStan\Node\StaticMethodCallableNode;
use PHPStan\Rules\Rule;
use PHPStan\Testing\RuleTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionClass;
use ReflectionFunction;
use ReflectionParameter;
use ScriptDevelopment\PhpstanWarroomRules\Rules\ForbidAdHocDateParsingRule;

use function array_keys;
use function class_exists;
use function function_exists;
use function implode;
use function is_a;
use function is_subclass_of;
use function sort;
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
     * Function names are resolved, not read off the token. PHP resolves an
     * unqualified call inside a namespace to a same-namespace declaration when
     * one exists, and `use function … as …` gives the global function a local
     * spelling — so the written token both over- and under-reports which global
     * function is being called.
     */
    public function testResolvesFunctionNamesRatherThanMatchingTheWrittenToken(): void
    {
        $this->analyse(
            [__DIR__ . '/../Fixtures/AdHocDateParsing/ResolvedFunctionNames.php'],
            [[self::expected('strtotime()'), 34]],
        );
    }

    /**
     * The decoded slot is addressed by parameter NAME first and by position
     * second. Reading argument zero in source order reports
     * `create(timezone: …)`, which hands the call no date input at all, and
     * stays silent on `create(month: 1, year: $raw)`, which hands it a string.
     */
    public function testReadsTheDecodedSlotByParameterName(): void
    {
        $this->analyse(
            [__DIR__ . '/../Fixtures/AdHocDateParsing/NamedArguments.php'],
            [
                [self::expected('CarbonImmutable::create()'), 27],
                [self::expected('CarbonImmutable::parse()'), 28],
                [self::expected('strtotime()'), 30],
            ],
        );
    }

    /**
     * PHP dispatches a static method case-insensitively, so the comparison
     * folds case. The call is still reported with the casing the source wrote,
     * which is where the reader has to go to fix it.
     */
    public function testFoldsTheCaseOfTheStaticMethodName(): void
    {
        $this->analyse(
            [__DIR__ . '/../Fixtures/AdHocDateParsing/UppercaseMethodName.php'],
            [
                [self::expected('CarbonImmutable::PARSE()'), 18],
                [self::expected('CarbonImmutable::CreateFromFormat()'), 19],
            ],
        );
    }

    /**
     * The boundary prefix matches on a namespace SEPARATOR, so a namespace that
     * merely starts with the same characters is not inside it.
     */
    public function testANamespaceSharingAPrefixIsNotInsideTheBoundary(): void
    {
        $this->analyse(
            [__DIR__ . '/../Fixtures/AdHocDateParsing/NamespacePrefixCollision.php'],
            [[self::expected('CarbonImmutable::parse()'), 19]],
        );
    }

    /**
     * Control for the pair above: a real sub-namespace of a configured boundary
     * keeps its exemption. Without this, tightening the prefix test to an exact
     * match would pass the collision case and silently cost every consumer its
     * documented sub-namespace behaviour.
     */
    public function testARealSubNamespaceOfTheBoundaryStaysExempt(): void
    {
        $this->analyse(
            [__DIR__ . '/../Fixtures/AdHocDateParsing/NestedCastNamespace.php'],
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
                [self::expected('CarbonImmutable::parseFromLocale()'), 33],
                [self::expected('CarbonImmutable::createFromFormat()'), 34],
                [self::expected('CarbonImmutable::rawCreateFromFormat()'), 35],
                [self::expected('CarbonImmutable::createFromIsoFormat()'), 36],
                [self::expected('CarbonImmutable::createFromLocaleFormat()'), 37],
                [self::expected('CarbonImmutable::createFromLocaleIsoFormat()'), 38],
                [self::expected('CarbonImmutable::createFromTimeString()'), 39],
                [self::expected('CarbonImmutable::createFromDate()'), 40],
                [self::expected('CarbonImmutable::createMidnightDate()'), 41],
                [self::expected('CarbonImmutable::createFromTime()'), 42],
                [self::expected('CarbonImmutable::create()'), 43],
                [self::expected('CarbonImmutable::make()'), 44],
                [self::expected('CarbonImmutable::createStrict()'), 45],
                [self::expected('CarbonImmutable::createSafe()'), 46],
                [self::expected('strtotime()'), 51],
                [self::expected('date_create()'), 52],
                [self::expected('date_create_immutable()'), 53],
                [self::expected('date_parse()'), 54],
                [self::expected('date_parse_from_format()'), 55],
                [self::expected('date_create_from_format()'), 56],
                [self::expected('date_create_immutable_from_format()'), 57],
                [self::expected('new DateTime()'), 62],
                [self::expected('new DateTimeImmutable()'), 63],
                [self::expected('DateTime::createFromFormat()'), 64],
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
        $this->ruleOverride = new ForbidAdHocDateParsingRule(self::createReflectionProvider(), ['App\Domain\Clock']);

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
        $this->ruleOverride = new ForbidAdHocDateParsingRule(self::createReflectionProvider(), ['App\Domain\Clock']);

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
     * Every decoded slot names a parameter that EXISTS on the real signature,
     * at the position the rule reads, under one of the spellings it accepts.
     *
     * The rule addresses the slot by name first and position second, so each
     * entry is three claims about `nesbot/carbon` and the two PHP natives: that
     * a parameter sits at that index, that its name is one the rule would
     * match, and — checked in the other direction — that every accepted
     * spelling is earned by a real signature rather than left behind by an
     * edit. A Carbon rename would break the named lookup silently: the rule
     * would simply stop seeing named arguments, with no error anywhere, and
     * every fixture here would stay green because they call these methods
     * positionally.
     *
     * The slot positions are the reason this cannot be eyeballed:
     * `createFromFormat` decodes its SECOND parameter and the locale-aware pair
     * decode their THIRD, so "argument zero" is wrong for five of the thirteen
     * methods and three of the seven functions.
     */
    public function testEveryDecodedSlotMatchesTheParameterItNames(): void
    {
        $declarers = [CarbonImmutable::class, Carbon::class, DateTime::class, DateTimeImmutable::class];
        $covered = [];

        foreach (self::ruleSlots('PARSING_METHODS') as $method => [$names, $position]) {
            $seen = [];

            foreach ($declarers as $class) {
                $reflection = new ReflectionClass($class);

                if (!$reflection->hasMethod($method)) {
                    continue;
                }

                $seen[$this->assertSlotParameter($reflection->getMethod($method)->getParameters(), $names, $position, sprintf('%s::%s()', $class, $method))] = true;
            }

            self::assertNotSame(
                [],
                $seen,
                sprintf('No date/time class declares %s(), so the rule reads a slot from a method that does not exist.', $method),
            );

            $this->assertEverySpellingIsEarned($names, array_keys($seen), $method);

            $covered[$method] = true;
        }

        // Denominator: the loop above visited every entry, so an entry that
        // silently stopped being iterated fails here rather than passing as a
        // clean run over a shorter map.
        self::assertSame(array_keys(self::ruleSlots('PARSING_METHODS')), array_keys($covered));

        foreach (self::ruleSlots('PARSING_FUNCTIONS') as $function => [$names, $position]) {
            self::assertTrue(function_exists($function), sprintf('The rule reads a slot from %s(), which does not exist.', $function));

            $actual = $this->assertSlotParameter((new ReflectionFunction($function))->getParameters(), $names, $position, sprintf('%s()', $function));

            $this->assertEverySpellingIsEarned($names, [$actual], $function);
        }

        [$names, $position] = self::ruleConstant('CONSTRUCTOR_SLOT');
        $seen = [];

        foreach ($declarers as $class) {
            $constructor = (new ReflectionClass($class))->getConstructor();

            self::assertNotNull($constructor, sprintf('%s has no constructor, so the New_ slot reads nothing.', $class));

            $seen[$this->assertSlotParameter($constructor->getParameters(), $names, $position, sprintf('new %s()', $class))] = true;
        }

        $this->assertEverySpellingIsEarned($names, array_keys($seen), 'the constructor slot');
    }

    /**
     * A configured prefix that already ends in a separator names the same
     * boundary as one that does not. Without the normalisation the separator
     * test appends a second backslash and the trailing spelling matches
     * nothing — a silently disarmed exemption for every consumer that writes it
     * that way.
     */
    public function testATrailingSeparatorInAConfiguredPrefixNamesTheSameBoundary(): void
    {
        $this->ruleOverride = new ForbidAdHocDateParsingRule(self::createReflectionProvider(), ['App\Domain\Clock\\']);

        $this->analyse(
            [__DIR__ . '/../Fixtures/AdHocDateParsing/ParsesInDomainClock.php'],
            [],
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
     * `CallLike::getArgs()` asserts `!isFirstClassCallable()` and returns raw
     * arguments otherwise, so reaching it with `Carbon::parse(...)` would be an
     * AssertionError under `zend.assertions=1` and a read of an undefined
     * property on a `VariadicPlaceholder` without it. Nothing in the rule
     * guards against that, and nothing needs to: PHPStan substitutes a
     * dedicated node for every first-class callable, and none of those nodes is
     * a `CallLike`, so the rule's own registration is what makes the argument
     * gate unreachable with one. That is a fact about PHPStan, not about this
     * code — a release that made those nodes `CallLike` would hand the rule a
     * shape it cannot read, with the fixture tripwire still green because the
     * rule would crash before reporting anything. This is what turns that into
     * a red build.
     *
     * @param class-string $node
     */
    #[DataProvider('firstClassCallableNodes')]
    public function testPhpstanFirstClassCallableNodesAreNotCallLike(string $node): void
    {
        self::assertTrue(class_exists($node), sprintf('%s no longer exists in PHPStan.', $node));

        self::assertFalse(
            is_a($node, CallLike::class, true),
            sprintf(
                '%s is now a CallLike, so PHPStan can hand a first-class callable to ForbidAdHocDateParsingRule and firstArgumentMayBeAString() will call getArgs() on it. Guard the choke point with isFirstClassCallable().',
                $node,
            ),
        );
    }

    /**
     * @return iterable<string, array{class-string}>
     */
    public static function firstClassCallableNodes(): iterable
    {
        yield 'static method' => [StaticMethodCallableNode::class];

        yield 'function' => [FunctionCallableNode::class];

        yield 'instance method' => [MethodCallableNode::class];
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
        return $this->ruleOverride ?? new ForbidAdHocDateParsingRule(self::createReflectionProvider());
    }

    /**
     * The parameter at one slot, asserted to exist and to carry a spelling the
     * rule would match. Returns the spelling it found, so the caller can check
     * the other direction.
     *
     * @param array<int, ReflectionParameter> $parameters
     * @param list<string>                    $names
     */
    private function assertSlotParameter(array $parameters, array $names, int $position, string $subject): string
    {
        self::assertArrayHasKey($position, $parameters, sprintf('%s has no parameter at position %d.', $subject, $position));

        $actual = $parameters[$position]->getName();

        self::assertContains(
            $actual,
            $names,
            sprintf(
                '%s parameter %d is $%s, but the rule accepts only $%s there — a named argument would silently never match.',
                $subject,
                $position,
                $actual,
                implode(' / $', $names),
            ),
        );

        return $actual;
    }

    /**
     * The other direction: a spelling the rule accepts that no real signature
     * uses is dead configuration, and it hides the day the live spelling
     * changed underneath it.
     *
     * @param list<string> $names
     * @param list<string> $seen
     */
    private function assertEverySpellingIsEarned(array $names, array $seen, string $subject): void
    {
        sort($names);
        sort($seen);

        self::assertSame(
            $names,
            $seen,
            sprintf('The rule accepts $%s for %s, but the real signatures only ever spell it $%s.', implode(' / $', $names), $subject, implode(' / $', $seen)),
        );
    }

    /**
     * A slot map read off the rule rather than restated — a copy here would
     * drift and this test would then verify the copy.
     *
     * @return array<string, array{0: list<string>, 1: int}>
     */
    private static function ruleSlots(string $constant): array
    {
        $slots = (new ReflectionClass(ForbidAdHocDateParsingRule::class))->getConstant($constant);

        self::assertIsArray($slots);

        return $slots;
    }

    /**
     * @return array{0: list<string>, 1: int}
     */
    private static function ruleConstant(string $constant): array
    {
        $value = (new ReflectionClass(ForbidAdHocDateParsingRule::class))->getConstant($constant);

        self::assertIsArray($value);

        return $value;
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
