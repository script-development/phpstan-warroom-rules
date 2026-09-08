<?php

declare(strict_types = 1);

namespace ScriptDevelopment\PhpstanWarroomRules\Rules;

use DateTimeInterface;
use Illuminate\Support\Facades\Date;
use PhpParser\Node;
use PhpParser\Node\Arg;
use PhpParser\Node\Expr\CallLike;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Expr\New_;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PHPStan\Analyser\Scope;
use PHPStan\Reflection\ReflectionProvider;
use PHPStan\Rules\IdentifierRuleError;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;
use PHPStan\Type\Constant\ConstantIntegerType;
use PHPStan\Type\Constant\ConstantStringType;
use PHPStan\Type\ObjectType;
use PHPStan\Type\Type;

use function array_key_exists;
use function array_map;
use function implode;
use function in_array;
use function mb_rtrim;
use function mb_strrpos;
use function mb_strtolower;
use function mb_substr;
use function sprintf;
use function str_starts_with;

/**
 * Forbids constructing a date/time value from a STRING anywhere outside the
 * namespaces where the boundary decode is allowed to live.
 *
 * Doctrine source: ADR-0020 Amendment 1 (Semantic Boundary Types) — "decoding
 * happens once, at the boundary"; ADR-0031 (instant vs wall-clock) supplies the
 * semantics the boundary type is obliged to carry.
 *
 * The failure this closes is not a parse that throws. It is a parse that
 * SUCCEEDS in fifty places with fifty slightly different readings of the same
 * string: a month-only bound, a `24:00` end-of-day, a bare `T` separator, an
 * IANA zone the next Action drops, a day treated as an instant. Every one of
 * those is a local, defensible decision, and the disagreement only becomes
 * visible in a report that does not balance. Measured seed: 30 of 354 crit
 * review findings in one week were date/cursor-bound semantics, each
 * re-implemented per Action; lokalekeuze PR #190 drew seven review rounds on a
 * single parser.
 *
 * WHAT FIRES (three call shapes, `getNodeType()` returns `CallLike` so one
 * registration sees all of them — mirrors `EnforceCurrentUserAttributeRule`):
 *
 *   1. `StaticCall` whose class RESOLVES to a `DateTimeInterface` subtype
 *      (`Carbon\Carbon`, `Carbon\CarbonImmutable`, `Illuminate\Support\Carbon`,
 *      `\DateTime`, `\DateTimeImmutable`, and any subclass) or to the
 *      `Illuminate\Support\Facades\Date` facade, with a method name in
 *      `PARSING_METHODS`.
 *   2. `New_` of a `DateTimeInterface` subtype.
 *   3. `FuncCall` whose callee RESOLVES to one of `PARSING_FUNCTIONS`.
 *      Resolution is through the `ReflectionProvider`, never the written
 *      token: PHP resolves an unqualified call to a same-namespace
 *      declaration when one exists, so `App\Support\strtotime()` is not the
 *      global function and must not fire. A `FuncCall` whose name is an `Expr`
 *      (a variable function) has no resolvable callee and stays out of scope.
 *
 * — each of them ONLY when the argument in the call's DECODED SLOT is present
 * and its type is not provably non-string (`decodedSlotMayBeAString()`). The
 * slot is named per verb, not assumed to be argument zero: `createFromFormat`
 * decodes its `$time`, the SECOND parameter, and `create` decodes its `$year`.
 * It is addressed by parameter NAME first and by position second, because
 * since PHP 8.0 a caller may name any argument and a named one does not sit at
 * its parameter's index — `create(timezone: 'Europe/Amsterdam')` hands the
 * call no date input at all, and `create(month: 1, year: $raw)` hands it a
 * string at index 1. That one gate is what separates a decode from everything
 * else the same names can do:
 * `Carbon::create(2026, 9, 7)` assembles a date from integers,
 * `Carbon::make($carbon)` re-wraps a value already decoded, `Carbon::create()`
 * and `date_create()` read the clock, `new CarbonImmutable(null, $tz)` is "now
 * in a zone" — none of them interprets a string, and none of them fires. The
 * gate's DIRECTION is deliberate: `mixed`, `int|string` and `?string` DO fire,
 * because an untyped value at a parse site (`$request->input('from')`) is
 * exactly the input the boundary type exists to pin down; a gate that demanded
 * a PROVEN string would exempt every one of those.
 *
 * `Carbon\CarbonInterface` extends `DateTimeInterface`, so ONE supertype check
 * covers the whole Carbon family as well as the two PHP natives — a second,
 * Carbon-specific check would be redundant and, worse, unfalsifiable: no input
 * could distinguish the two conditions, so nothing could ever prove the second
 * one still worked. That inheritance is load-bearing rather than incidental, so
 * it is asserted in the test suite (`testCarbonInterfaceExtendsTheDateTimeAnchor`)
 * instead of claimed in this docblock — a Carbon release that stopped extending
 * `DateTimeInterface` would silently narrow this rule to the two PHP natives,
 * and the assertion is what turns that into a red build.
 *
 * WHAT DOES NOT FIRE, by design — none of these decodes a string:
 *   - `now()`, `today()`, `yesterday()`, `tomorrow()`, `instance()`,
 *     `fromSerialized()` and the `createFromTimestamp*` family. A timestamp is
 *     already an instant; there is nothing to interpret. Each of these is named
 *     in `NON_DECODING_FACTORIES` with its reason, so a factory belonging to
 *     neither constant is a failed test rather than a silent escape.
 *   - Any listed call whose decoded slot is absent or provably not a string
 *     (see the gate above): zero-argument construction and factories, integer
 *     components, `null`, an existing `DateTimeInterface` value.
 *   - Instance calls: `$date->format(...)`, `$date->addDays(1)`,
 *     `$date->startOfDay()`. The value object is already decoded; moving it
 *     around is what the boundary type exists FOR.
 *   - A `StaticCall` on a dynamic class expression (`$class::parse(...)`), and
 *     a `New_` on a dynamic class expression. An accepted false negative: the
 *     receiver has no resolvable name, and guessing one would be a
 *     false-positive source a boundary rule cannot afford.
 *   - A first-class callable (`Carbon::parse(...)`). Nothing is decoded at
 *     that site, and PHPStan does not hand the node to this rule. Accepted
 *     false negative, pinned by fixture.
 *   - A local class that merely happens to be NAMED `Carbon` and declares a
 *     static `parse()`. Resolution is by TYPE, never by string-matching the
 *     class name — pinned by a negative fixture.
 *
 * THE ALLOWED NAMESPACES ARE CONFIGURATION, not a hardcoded carve-out. A class
 * whose namespace EQUALS an entry in `dateParsingNamespaces`, or continues one
 * across a namespace SEPARATOR, is silent — so `App\Casts\Money` is inside
 * `App\Casts` and `App\CastsReport` is not. The default
 * `['App\Support\Time', 'App\Support\DateTime', 'App\Casts']` covers the two
 * boundary doors ADR-0020 Amd 1 names: a dedicated decode helper (emmie ships
 * `App\Support\DateTime\InstantParser`; lokalekeuze's lands under
 * `App\Support\Time`) and the row-to-model cast (`App\Casts`), where the string
 * genuinely arrives from outside and must become a value object exactly once. A
 * territory narrows or widens by configuration; the rule itself knows nothing
 * about any territory's layout.
 *
 * A class in the GLOBAL namespace (`$scope->getNamespace() === null`) is
 * outside every configured prefix and therefore fires. That is deliberate:
 * consumers analyse `app/`, which is namespaced throughout, and a
 * global-namespace parse is by definition not inside a boundary namespace.
 *
 * @implements Rule<CallLike>
 */
final class ForbidAdHocDateParsingRule implements Rule
{
    /**
     * The other half of Carbon's static factory surface: methods that return an
     * instance without interpreting any argument as a date/time string. Listing
     * them is what lets `testEveryCarbonStaticFactoryIsClassifiedAsDecodingOrNot`
     * fail on a factory that is in NEITHER constant — the state a reviewer
     * found by hand on the fourth consecutive round of PR #71.
     *
     * The reason is not decoration — it is the claim the entry makes, and the
     * completeness test refuses any entry whose real signature names a decoded
     * slot (`$time`, `$datetime`, `$year`, `$hour`, `$var`) with a type that
     * admits a string. That is what stops a future author silencing a decoder
     * by moving its name down here.
     *
     * Analysis never reads it: a name that is absent from `PARSING_METHODS`
     * already returns no error, so consulting this list at analysis time would
     * be a branch no input could distinguish. Its only reader is the
     * completeness test, and `public` is what says so — a private constant
     * nothing in this class consults is `classConstant.unused`, and the fix for
     * that is to declare the external reader, not to invent a use.
     *
     * @var array<string, string>
     */
    public const array NON_DECODING_FACTORIES = [
        'now' => 'Reads the clock. There is no argument to interpret.',
        'today' => 'Reads the clock, truncated to the day.',
        'tomorrow' => 'Reads the clock, offset by a day.',
        'yesterday' => 'Reads the clock, offset by a day.',
        'instance' => 'Re-wraps a DateTimeInterface that is already decoded.',
        'createfrominterface' => 'Re-wraps a DateTimeInterface that is already decoded.',
        'createfromimmutable' => 'Re-wraps a DateTimeImmutable that is already decoded.',
        'createfrommutable' => 'Re-wraps a DateTime that is already decoded.',
        'createfromid' => 'Reads the embedded timestamp of an ordered UUID or ULID, not a date string.',
        'fromserialized' => 'Restores a previously serialized instance.',
        '__set_state' => 'Restores an instance from its var_export() form.',
        'createfromtimestamp' => 'A timestamp is already an instant; nothing is interpreted.',
        'createfromtimestamputc' => 'A timestamp is already an instant; nothing is interpreted.',
        'createfromtimestampms' => 'A timestamp is already an instant; nothing is interpreted.',
        'createfromtimestampmsutc' => 'A timestamp is already an instant; nothing is interpreted.',
        'startoftime' => 'The lowest representable instant. It takes no argument.',
        'endoftime' => 'The highest representable instant. It takes no argument.',
        'gettestnow' => 'Returns the configured test clock; it is a getter, not a factory over an argument.',
    ];

    private const string IDENTIFIER = 'forbidAdHocDateParsing.stringParsedOutsideBoundary';

    /**
     * The supertype every date/time class this rule cares about shares.
     * `Carbon\CarbonInterface` extends it, so does `\DateTime` and
     * `\DateTimeImmutable`, and userland cannot implement it directly — which
     * makes it an exact fit for "is this a date/time class" with no ancestry
     * list to maintain.
     */
    private const string DATE_TIME_ANCHOR = DateTimeInterface::class;

    private const string DATE_FACADE = Date::class;

    /**
     * Static factory methods that interpret a STRING, mapped to the SLOT that
     * carries it — the accepted parameter spellings and the position. The names
     * alone do not discriminate: `create`, `createFromDate`, `createFromTime`,
     * `createStrict` and `createSafe` also accept integer components, `make`
     * and `parse` also re-wrap an existing value, and every one of them is a
     * clock read with no argument at all — Carbon's `create()` delegates to
     * `parse()` exactly when its `$year` is a non-numeric string. The gate in
     * `decodedSlotMayBeAString()` is what turns a name here into a finding, so
     * the list stays wide and the gate stays narrow.
     *
     * The slot is NOT argument zero for the `*FromFormat` family: the format
     * string sits there and the decoded value is the `$time` after it, two
     * places along for the locale-aware pair. `createFromFormat` carries two
     * spellings because Carbon names that parameter `$time` and the two PHP
     * natives name it `$datetime`; every slot here is pinned against the real
     * signatures in `testEveryDecodedSlotMatchesTheParameterItNames`.
     *
     * The list stays wide, and what keeps it COMPLETE is not care:
     * `testEveryCarbonStaticFactoryIsClassifiedAsDecodingOrNot` reflects
     * Carbon's real static surface and fails on any factory that is neither a
     * key here nor a key of `NON_DECODING_FACTORIES`. Three methods were missing
     * while the list was maintained by hand: a reviewer named `parseFromLocale`
     * and `createMidnightDate`, and a reflection sweep over the same surface
     * then found `rawCreateFromFormat`, which nobody reading the list had seen.
     * That is what moved the completeness claim out of this docblock and into a
     * test.
     *
     * Keys are lower-case because PHP dispatches a static method
     * case-insensitively and the lookup folds the written identifier to match.
     *
     * @var array<string, array{0: list<string>, 1: int}>
     */
    private const array PARSING_METHODS = [
        'parse' => [['time'], 0],
        'rawparse' => [['time'], 0],
        'parsefromlocale' => [['time'], 0],
        'createfromformat' => [['time', 'datetime'], 1],
        'rawcreatefromformat' => [['time'], 1],
        'createfromisoformat' => [['time'], 1],
        'createfromlocaleformat' => [['time'], 2],
        'createfromlocaleisoformat' => [['time'], 2],
        'createfromtimestring' => [['time'], 0],
        'createfromdate' => [['year'], 0],
        'createmidnightdate' => [['year'], 0],
        'createfromtime' => [['hour'], 0],
        'create' => [['year'], 0],
        'make' => [['var'], 0],
        'createstrict' => [['year'], 0],
        'createsafe' => [['year'], 0],
    ];

    /**
     * The slot `new DateTimeImmutable(...)` / `new CarbonImmutable(...)`
     * decodes. Carbon spells that constructor parameter `$time`, the two PHP
     * natives spell it `$datetime`, and `new CarbonImmutable(timezone: $tz)` is
     * "now in a zone" with nothing in the slot at all.
     *
     * @var array{0: list<string>, 1: int}
     */
    private const array CONSTRUCTOR_SLOT = [['time', 'datetime'], 0];

    /**
     * Procedural equivalents of the same decode, mapped to the same kind of
     * slot — including the two `*_from_format` aliases of `createFromFormat`,
     * the method the seed measured as the second-largest offender, which a list
     * without them would have left a one-token escape hatch for. Every one of
     * these spells its decoded parameter `$datetime`; the three `*_from_format`
     * shapes carry it after the format, at index 1.
     *
     * @var array<string, array{0: list<string>, 1: int}>
     */
    private const array PARSING_FUNCTIONS = [
        'strtotime' => [['datetime'], 0],
        'date_create' => [['datetime'], 0],
        'date_create_immutable' => [['datetime'], 0],
        'date_create_from_format' => [['datetime'], 1],
        'date_create_immutable_from_format' => [['datetime'], 1],
        'date_parse' => [['datetime'], 0],
        'date_parse_from_format' => [['datetime'], 1],
    ];

    /** @var list<string> */
    private array $dateParsingNamespaces;

    /**
     * @param list<string> $dateParsingNamespaces namespace prefixes inside
     *                                            which a date/time string may
     *                                            legitimately be decoded. A
     *                                            namespace matches when it
     *                                            equals a prefix or continues
     *                                            it across a separator, so
     *                                            sub-namespaces match and
     *                                            `App\CastsReport` does not.
     *                                            The default names the two
     *                                            boundary doors of ADR-0020
     *                                            Amd 1 — a dedicated decode
     *                                            helper and the row-to-model
     *                                            cast.
     */
    public function __construct(
        private ReflectionProvider $reflectionProvider,
        array $dateParsingNamespaces = ['App\Support\Time', 'App\Support\DateTime', 'App\Casts'],
    ) {
        // A configured `App\Casts\` and `App\Casts` name the same boundary.
        // Normalising once here keeps the separator test below from treating
        // the trailing form as a second, never-matching spelling.
        $this->dateParsingNamespaces = array_map(
            static fn(string $prefix): string => mb_rtrim($prefix, '\\'),
            $dateParsingNamespaces,
        );
    }

    public function getNodeType(): string
    {
        return CallLike::class;
    }

    public function processNode(Node $node, Scope $scope): array
    {
        if ($this->insideBoundaryNamespace($scope)) {
            return [];
        }

        if ($node instanceof StaticCall) {
            return $this->processStaticCall($node, $scope);
        }

        if ($node instanceof New_) {
            return $this->processNew($node, $scope);
        }

        if ($node instanceof FuncCall) {
            return $this->processFuncCall($node, $scope);
        }

        return [];
    }

    /**
     * Containing-class gate. A class whose namespace starts with a configured
     * prefix is where the decode BELONGS, so the rule stays silent there and
     * nowhere else.
     */
    private function insideBoundaryNamespace(Scope $scope): bool
    {
        $namespace = $scope->getNamespace();

        if ($namespace === null) {
            return false;
        }

        foreach ($this->dateParsingNamespaces as $prefix) {
            // The separator is what makes this a NAMESPACE test rather than a
            // character-prefix test: without it `App\CastsReport` is inside
            // `App\Casts` and every namespace sharing an opening substring
            // with a boundary silently inherits its exemption.
            if ($namespace === $prefix || str_starts_with($namespace, $prefix . '\\')) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<IdentifierRuleError>
     */
    private function processStaticCall(StaticCall $node, Scope $scope): array
    {
        if (!$node->name instanceof Identifier) {
            return [];
        }

        $method = $node->name->toString();

        // PHP dispatches `PARSE()` and `parse()` to the same method, so the
        // written identifier is folded before the lookup. It is still what the
        // error reports — that is the token the reader has to go and change.
        $folded = mb_strtolower($method);

        if (!array_key_exists($folded, self::PARSING_METHODS)) {
            return [];
        }

        [$names, $position] = self::PARSING_METHODS[$folded];

        if (!$this->decodedSlotMayBeAString($node, $scope, $names, $position)) {
            return [];
        }

        $class = $this->resolveClassName($node->class, $scope);

        if ($class === null) {
            return [];
        }

        if ($class !== self::DATE_FACADE && !$this->isDateTimeClass($class)) {
            return [];
        }

        return [$this->buildError(sprintf('%s::%s()', $this->shortName($class), $method))];
    }

    /**
     * @return list<IdentifierRuleError>
     */
    private function processNew(New_ $node, Scope $scope): array
    {
        [$names, $position] = self::CONSTRUCTOR_SLOT;

        if (!$this->decodedSlotMayBeAString($node, $scope, $names, $position)) {
            return [];
        }

        $class = $this->resolveClassName($node->class, $scope);

        if ($class === null || !$this->isDateTimeClass($class)) {
            return [];
        }

        return [$this->buildError(sprintf('new %s()', $this->shortName($class)))];
    }

    /**
     * @return list<IdentifierRuleError>
     */
    private function processFuncCall(FuncCall $node, Scope $scope): array
    {
        if (!$node->name instanceof Name) {
            return [];
        }

        // The callee is RESOLVED, never read off the token. An unqualified
        // call inside a namespace resolves to a same-namespace declaration
        // when one exists, so a local `strtotime()` helper is not the global
        // function and must not fire; `use function strtotime as decode;`
        // is the same question from the other side.
        if (!$this->reflectionProvider->hasFunction($node->name, $scope)) {
            return [];
        }

        $function = mb_strtolower($this->reflectionProvider->getFunction($node->name, $scope)->getName());

        if (!array_key_exists($function, self::PARSING_FUNCTIONS)) {
            return [];
        }

        [$names, $position] = self::PARSING_FUNCTIONS[$function];

        if (!$this->decodedSlotMayBeAString($node, $scope, $names, $position)) {
            return [];
        }

        return [$this->buildError(sprintf('%s()', $function))];
    }

    /**
     * The argument gate shared by all three shapes: a call decodes only if the
     * argument in its decoded slot is present and the analyser cannot prove it
     * is NOT a string. Absent argument, integer components, `null` and an
     * existing value object all resolve `isString()` to "no" and are silent;
     * `string`, `mixed`, `int|string` and `?string` are not provably
     * non-string and fire.
     *
     * `getArgs()` is safe to call unguarded even though it asserts
     * `!isFirstClassCallable()`: PHPStan substitutes `StaticMethodCallableNode`
     * / `FunctionCallableNode` / `MethodCallableNode` for a first-class
     * callable, and none of those extends `CallLike`, so this rule's
     * registration cannot receive one. A guard here would be unreachable code
     * no test could pin and every mutation of it would escape. The upstream
     * substitution is asserted in the rule test instead.
     *
     * @param list<string> $names
     */
    private function decodedSlotMayBeAString(CallLike $node, Scope $scope, array $names, int $position): bool
    {
        $type = $this->decodedSlotType($node, $scope, $names, $position);

        return $type !== null && !$type->isString()->no();
    }

    /**
     * The TYPE that reaches the decoded slot, addressed by NAME first and by
     * position second — the reader `ForbidCredentialCastBypassRule` uses, for
     * the same reason. Null means no argument reaches the slot at all.
     *
     * A named argument does not sit at its parameter's position:
     * `create(month: 1, year: $raw)` puts the decoded value at index 1, so
     * reading index 0 finds an integer and the parse passes silently, while
     * `create(timezone: 'Europe/Amsterdam')` puts a string at index 0 that is
     * not a date input at all and is reported for it.
     *
     * A TYPE rather than an `Arg`, because an unpacked argument breaks the
     * one-argument-one-parameter correspondence the AST otherwise has. Spread
     * an array into a call and php-parser carries ONE `Arg` whose value is the
     * whole ARRAY, however many parameters it fills — so reading that `Arg`'s
     * type asks "is this array a string", the analyser answers a confident no,
     * and `Carbon::parse(...$raw)` decodes a string in silence. The slot's type
     * has to come from INSIDE the unpacked array, at the offset the slot
     * actually lands on.
     *
     * @param list<string> $names accepted spellings of the parameter, because
     *                            Carbon and the two PHP natives disagree on
     *                            `$time` versus `$datetime`
     */
    private function decodedSlotType(CallLike $node, Scope $scope, array $names, int $position): ?Type
    {
        $args = $node->getArgs();

        // Name pass. A string-keyed array spread IS a named-argument spread —
        // `parse(...['time' => $raw])` names the slot exactly as
        // `parse(time: $raw)` does — so the key is asked of the unpacked array
        // here rather than left to the positional pass, which counts integer
        // offsets and would never see it.
        foreach ($args as $argument) {
            if ($argument->name instanceof Identifier && in_array($argument->name->toString(), $names, true)) {
                return $scope->getType($argument->value);
            }

            if (!$argument->unpack) {
                continue;
            }

            $unpacked = $scope->getType($argument->value);

            foreach ($names as $name) {
                $key = new ConstantStringType($name);

                if (!$unpacked->hasOffsetValueType($key)->no()) {
                    return $unpacked->getOffsetValueType($key);
                }
            }
        }

        // Positional pass. PHP requires every positional argument before the
        // first named one, so positional slots are contiguous from zero — but
        // an unpacked array fills as many of them as it has elements, so the
        // index is counted rather than read off the argument list. Counting
        // rather than refusing whenever ANY argument is named or unpacked keeps
        // `parse($raw, timezone: 'UTC')` covered.
        $index = 0;

        foreach ($args as $argument) {
            if ($argument->name !== null) {
                // The guard is what stops a named argument being counted as a
                // positional slot: `create(timezone: 'Europe/Amsterdam')` would
                // otherwise answer index 0 with a string that is not a date
                // input at all. `continue` and `break` are the same statement
                // here — PHP's parser rejects both a positional argument and an
                // unpack after a named one, so nothing following this can fill
                // a positional slot — which is why a mutation between them
                // survives and cannot be killed by any input.
                continue;
            }

            if (!$argument->unpack) {
                if ($index === $position) {
                    return $scope->getType($argument->value);
                }

                $index++;

                continue;
            }

            $unpacked = $scope->getType($argument->value);

            if ($position >= $index) {
                $offset = new ConstantIntegerType($position - $index);

                // Not provably absent is the same direction the gate itself
                // takes: an array with no element information yields `mixed`
                // here and therefore FIRES, because an unpacked
                // `$request->input()` bag is exactly the input the boundary
                // type exists to pin down.
                if (!$unpacked->hasOffsetValueType($offset)->no()) {
                    return $unpacked->getOffsetValueType($offset);
                }
            }

            $size = $unpacked->getArraySize();

            // The slot is past this array. Skipping it needs its LENGTH, which
            // only a constant array has; otherwise every later position is
            // unknowable and there is nothing further to say.
            if (!$size instanceof ConstantIntegerType) {
                return null;
            }

            $index += $size->getValue();
        }

        return null;
    }

    /**
     * Resolves a class-position node to an FQCN through the SCOPE, so an
     * aliased import (`use Carbon\CarbonImmutable as C;`) resolves to the real
     * class and a dynamic expression resolves to nothing.
     */
    private function resolveClassName(Node $class, Scope $scope): ?string
    {
        if (!$class instanceof Name) {
            return null;
        }

        return $scope->resolveName($class);
    }

    private function isDateTimeClass(string $class): bool
    {
        return (new ObjectType(self::DATE_TIME_ANCHOR))->isSuperTypeOf(new ObjectType($class))->yes();
    }

    private function shortName(string $class): string
    {
        $position = mb_strrpos($class, '\\');

        return $position === false ? $class : mb_substr($class, $position + 1);
    }

    private function buildError(string $call): IdentifierRuleError
    {
        return RuleErrorBuilder::message(sprintf(
            'Ad-hoc date parsing (%s) outside %s: decode the string once at the boundary and pass the value object (ADR-0020 Amd 1).',
            $call,
            implode(', ', $this->dateParsingNamespaces),
        ))
            ->identifier(self::IDENTIFIER)
            ->build();
    }
}
