<?php

declare(strict_types = 1);

namespace ScriptDevelopment\PhpstanWarroomRules\Rules;

use DateTimeInterface;
use Illuminate\Support\Facades\Date;
use PhpParser\Node;
use PhpParser\Node\Expr\CallLike;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Expr\New_;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\IdentifierRuleError;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;
use PHPStan\Type\ObjectType;

use function implode;
use function in_array;
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
 *   2. `New_` of a `DateTimeInterface` subtype WITH AT LEAST ONE ARGUMENT.
 *   3. `FuncCall` to one of `PARSING_FUNCTIONS`.
 *
 * `Carbon\CarbonInterface` extends `DateTimeInterface`, so ONE supertype check
 * covers the whole Carbon family as well as the two PHP natives — a second,
 * Carbon-specific check would be redundant and, worse, unfalsifiable: no input
 * could distinguish the two conditions, so nothing could ever prove the second
 * one still worked. That inheritance is load-bearing rather than incidental, so
 * it is asserted in the test suite (`testCarbonInterfaceExtendsTheAnchor`)
 * instead of claimed in this docblock — a Carbon release that stopped extending
 * `DateTimeInterface` would silently narrow this rule to the two PHP natives,
 * and the assertion is what turns that into a red build.
 *
 * WHAT DOES NOT FIRE, by design — none of these decodes a string:
 *   - `now()`, `today()`, `yesterday()`, `tomorrow()`, `instance()`,
 *     `fromSerialized()` and the `createFromTimestamp*` family. A timestamp is
 *     already an instant; there is nothing to interpret.
 *   - Zero-argument `new \DateTimeImmutable()` / `new CarbonImmutable()` —
 *     that is "now", not a parse.
 *   - Instance calls: `$date->format(...)`, `$date->addDays(1)`,
 *     `$date->startOfDay()`. The value object is already decoded; moving it
 *     around is what the boundary type exists FOR.
 *   - A `StaticCall` on a dynamic class expression (`$class::parse(...)`), and
 *     a `New_` on a dynamic class expression. An accepted false negative: the
 *     receiver has no resolvable name, and guessing one would be a
 *     false-positive source a boundary rule cannot afford.
 *   - A local class that merely happens to be NAMED `Carbon` and declares a
 *     static `parse()`. Resolution is by TYPE, never by string-matching the
 *     class name — pinned by a negative fixture.
 *
 * THE ALLOWED NAMESPACES ARE CONFIGURATION, not a hardcoded carve-out. A class
 * whose namespace `str_starts_with` any entry in `dateParsingNamespaces` is
 * silent, so sub-namespaces match their prefix naturally. The default
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
     * Static factory methods that take a STRING and interpret it. Every entry
     * decodes; nothing here is a clock read or a timestamp conversion.
     *
     * @var list<string>
     */
    private const array PARSING_METHODS = [
        'parse',
        'rawParse',
        'createFromFormat',
        'createFromIsoFormat',
        'createFromLocaleFormat',
        'createFromLocaleIsoFormat',
        'createFromTimeString',
        'createFromDate',
        'createFromTime',
        'create',
        'make',
        'createStrict',
        'createSafe',
    ];

    /**
     * Procedural equivalents of the same decode.
     *
     * @var list<string>
     */
    private const array PARSING_FUNCTIONS = [
        'strtotime',
        'date_create',
        'date_create_immutable',
        'date_parse',
        'date_parse_from_format',
    ];

    /**
     * @param list<string> $dateParsingNamespaces namespace prefixes inside
     *                                            which a date/time string may
     *                                            legitimately be decoded
     *                                            (matched via
     *                                            `str_starts_with`, so
     *                                            sub-namespaces match their
     *                                            prefix). The default names the
     *                                            two boundary doors of
     *                                            ADR-0020 Amd 1 — a dedicated
     *                                            decode helper and the
     *                                            row-to-model cast.
     */
    public function __construct(
        private array $dateParsingNamespaces = ['App\Support\Time', 'App\Support\DateTime', 'App\Casts'],
    ) {}

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
            return $this->processFuncCall($node);
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
            if (str_starts_with($namespace, $prefix)) {
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

        if (!in_array($method, self::PARSING_METHODS, true)) {
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
     * A zero-argument constructor is "now", never a parse — the argument count
     * is the whole discriminator, so it is checked before anything else that
     * could mask it.
     *
     * @return list<IdentifierRuleError>
     */
    private function processNew(New_ $node, Scope $scope): array
    {
        if ($node->getArgs() === []) {
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
    private function processFuncCall(FuncCall $node): array
    {
        if (!$node->name instanceof Name) {
            return [];
        }

        $function = mb_strtolower($node->name->toString());

        if (!in_array($function, self::PARSING_FUNCTIONS, true)) {
            return [];
        }

        return [$this->buildError(sprintf('%s()', $function))];
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
