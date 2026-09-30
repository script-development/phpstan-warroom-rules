<?php

declare(strict_types = 1);

namespace ScriptDevelopment\PhpstanWarroomRules\Rules;

use PhpParser\Node;
use PHPStan\Analyser\Scope;
use PHPStan\Node\CollectedDataNode;
use PHPStan\Rules\IdentifierRuleError;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;
use ScriptDevelopment\PhpstanWarroomRules\Collectors\CacheKeyTaintCollector;

use function array_key_exists;
use function array_keys;
use function explode;
use function implode;
use function in_array;
use function ksort;
use function mb_substr;
use function sort;
use function sprintf;
use function str_starts_with;

/**
 * Forbids a cache key derived from an Eloquent attribute whose cast encrypts it
 * — `encrypted`, any `encrypted:*` form, `AsEncryptedArrayObject`,
 * `AsEncryptedCollection` — whether the attribute reaches the key raw,
 * concatenated, interpolated, hashed, or carried inside a value object built in
 * another class.
 *
 * Doctrine source: war-room §Architectural Principles #10 (cache keys must be
 * rotation-invariant when derived from rotatable credentials); ISO 27001
 * A.5.33 on the compliance territories. War-room enforcement queue #24.
 *
 * A key built from a credential puts the credential, or a digest standing in
 * for it, into a second durable store, and every entry keyed by the old
 * credential outlives a rotation. Hashing does not make it compliant: the
 * digest is still keyed by the credential. Key by the owning entity's id.
 *
 * `CacheKeyTaintCollector` records, per node, candidate reads, assignments,
 * returns, calls and cache operations; this rule solves them over the whole
 * analysed set. A read is a SOURCE when the receiver's TYPE is a model whose
 * resolved cast encrypts the attribute — resolved here, on every run, by
 * `ForbidCredentialCastBypassRule`'s declaration walk, so the two rules agree
 * on which column is a credential and a changed `casts()` body is seen without
 * a cold cache. A SINK is the key argument of a call on anything typed as a
 * Laravel cache handle (the `Illuminate\Contracts\Cache` / `Illuminate\Cache`
 * families, the `Cache` facade, the `cache()` helper) or on the `RateLimiter`,
 * which stores its counters under the key it is handed: the argument PHP binds
 * to the key parameter, named or positional, a spread array literal read as
 * its entries — every argument when any other spread or a missing name leaves
 * that unknown — read the way Laravel reads keys out of it (`getMultiple()` /
 * `deleteMultiple()` by an array's values, `put()` / `putMany()` / `set()` /
 * `setMultiple()` / `cache()` by its own keys, `get()` / `many()` by a string
 * key or else the value, and a raw `Store`'s `many()` by every value,
 * as the Redis, Memcached, database and DynamoDB stores do). A call into analysed
 * code yields the callee's return SUMMARY for the arguments at that call site,
 * so a shared key helper handed an id at one site and the credential at another
 * reports only the second.
 *
 * REACH — what the analysis follows: a named attribute read on a model-typed
 * receiver, or on the model branches of a union receiver (a property,
 * `getAttribute()`, array access — the name a literal or a value of one
 * constant string type); locals, and the keys a local map
 * holds; properties; the bodies of CLASS METHODS, their parameters bound per
 * call site and their returns; and a call into code it has no body for, read
 * from its arguments. Outside that reach, each a known false negative; a
 * bracketed name is the `Imprecision.php` fixture row pinning that shape:
 *   - an attribute named at runtime (`$vault->{$name}`, `getAttribute($name)`)
 *     [`missesAnAttributeNamedAtRuntime`];
 *   - an attribute returned by a model method the analysis has no body for —
 *     Eloquent's own bulk readers (`toArray()`, `only()`, `getAttributes()`),
 *     or a model outside the analysed paths
 *     [`missesAnAttributeReturnedByAModelMethodWithNoBody`];
 *   - a NAMED FUNCTION's body — a credential it reads for itself
 *     [`missesACredentialReadInsideANamedFunction`], or a parameter it hands to
 *     a cache sink [`missesACredentialHandedToANamedFunctionsSink`];
 *   - a closure's or arrow function's body, invoked or not
 *     [`missesACredentialReturnedByAClosure`];
 *   - dispatch through an interface or abstract method to what an
 *     implementation reads for itself — only the call's arguments count (on a
 *     union receiver, on top of what its analysed branches return)
 *     [`missesACredentialReadInsideAnInterfaceImplementation`];
 *   - a write into a variable by reference from inside a call (`preg_match()`'s
 *     matches) [`missesAMatchWrittenByReferenceInsideACall`];
 *   - array access on a cache handle (`$cache[$key]`)
 *     [`missesArrayAccessOnACacheHandle`], or a cache handle or method PHPStan
 *     cannot resolve — `app('cache')` without larastan, a `mixed` value, a
 *     dynamic method name [unpinned];
 *   - a cast added at runtime (`mergeCasts()`) [`missesACastAddedAtRuntime`],
 *     or a cast class other than the two above [unpinned].
 * A model whose casts this rule cannot read is NOT among them: a read of any
 * of its attributes — its id included — that reaches a cache key reports under
 * `forbidCredentialDerivedCacheKey.castMapUnreadable`, since whether that
 * attribute is encrypted cannot be told; a read that reaches no cache key does
 * not.
 *
 * False positives, each by construction, and each only ever adding a report:
 * the analysis is flow-insensitive but for one shape — a `$name = …;`
 * statement replaces every earlier value of `$name` for the reads after it in
 * its own statement list, unless the method holds a `goto` or the value came
 * through a reference or a closure — so a key reassigned inside a branch, even
 * on every branch, still reports; it is instance-insensitive — a property is
 * one slot shared by every instance of its class and its subclasses, so a
 * value object constructed from the credential anywhere taints its methods
 * everywhere; a map that reaches a sink through a call, a parameter, a
 * property or another map's entry counts its values as keys; a spread that
 * spreads again, or whose keys are not known, hands its whole value to every
 * parameter from its position on; and a value returned by a call that was
 * HANDED the credential — a provider response fetched with the API key —
 * carries the credential, so a key built from a field of that response
 * reports.
 *
 * A sink inside a helper that receives the credential as a parameter reports
 * once, at the helper's line, whichever caller handed it the credential.
 *
 * Suppression: standard PHPStan inline-ignore mechanism on the identifier
 * `forbidCredentialDerivedCacheKey.keyFromEncryptedAttribute`.
 *
 * @phpstan-import-type Facts from CacheKeyTaintCollector
 * @phpstan-import-type Term from CacheKeyTaintCollector
 *
 * @implements Rule<CollectedDataNode>
 */
final class ForbidCredentialDerivedCacheKeyRule implements Rule
{
    private const string IDENTIFIER = 'forbidCredentialDerivedCacheKey.keyFromEncryptedAttribute';

    private const string UNVERIFIABLE_IDENTIFIER = 'forbidCredentialDerivedCacheKey.castMapUnreadable';

    /** Marks a label whose attribute sits on a model with unreadable casts; no class name starts with it. */
    private const string UNVERIFIABLE = '?';

    /** Marks a value a later statement cannot replace; an array key no offset can take. */
    private const string UNKILLABLE = 'ref';

    /** Each half a local keeps apart => the prefix of its slot; no PHP variable name can start with one. */
    private const array LOCAL_SLOTS = ['v' => '', 'k' => '@keys:', 'e' => '@values:', 'i' => '@index:'];

    /** @var array<string, array<string, true>> scope => parameter names, for every analysed method */
    private array $parameters = [];

    /** @var array<string, array<string, array{Term, array<string, array<string, Term>>}>> scope => call id => fallback term, callee => parameter => argument term */
    private array $calls = [];

    /** @var array<string, array<int|string, array{array<string, true>, array<string, true>}>> `scope|variable` => offset it was given at => labels, parameters */
    private array $values = [];

    /** @var array<string, array{array<string, true>, array<string, true>}> scope => labels, parameters of its return value */
    private array $returns = [];

    /** @var array<string, array<string, list<array{int, int, int}>>> scope => variable => assignment offset, statement end, list end */
    private array $kills = [];

    /** @var array<string, array<string, true>> heap slot => labels */
    private array $heap = [];

    /** @var array<string, array<string, true>> `scope|parameter` => labels bound at any call site */
    private array $bound = [];

    private bool $changed = false;

    public function __construct(
        private ForbidCredentialCastBypassRule $castReader,
    ) {}

    public function getNodeType(): string
    {
        return CollectedDataNode::class;
    }

    /**
     * @return list<IdentifierRuleError>
     */
    public function processNode(Node $node, Scope $scope): array
    {
        $this->parameters = [];
        $this->calls = [];
        $this->values = [];
        $this->returns = [];
        $this->kills = [];
        $this->heap = [];
        $this->bound = [];

        $flows = [];
        $binds = [];
        $sinks = [];

        foreach ($node->get(CacheKeyTaintCollector::class) as $file => $collected) {
            foreach ($collected as $facts) {
                $this->index($facts, $file, $flows, $binds, $sinks);
            }
        }

        do {
            $this->changed = false;

            foreach ($flows as [$scopeKey, $target, $term, $at]) {
                $this->flow($scopeKey, $target, $term, $at);
            }

            foreach ($binds as [$scopeKey, $callee, $parameter, $term]) {
                $slot = $callee . '|' . $parameter;
                $this->bound[$slot] = $this->merged($this->bound[$slot] ?? [], $this->concrete($this->evaluate($term, $scopeKey), $scopeKey));
            }
        } while ($this->changed);

        return $this->errors($sinks);
    }

    /**
     * @param Facts                                                               $facts
     * @param list<array{string, string, Term, int|null}>                         $flows
     * @param list<array{string, string, string, Term}>                           $binds
     * @param array<string, array<int, array<string, list<array{string, Term}>>>> $sinks
     */
    private function index(array $facts, string $file, array &$flows, array &$binds, array &$sinks): void
    {
        $scopeKey = $facts['scope'];

        if (array_key_exists('params', $facts)) {
            $this->parameters[$scopeKey] = [];

            foreach ($facts['params'] as $parameter) {
                $this->parameters[$scopeKey][$parameter] = true;
            }
        }

        foreach ($facts['kills'] ?? [] as [$variable, $at, $statementEnd, $listEnd]) {
            $this->kills[$scopeKey][$variable][] = [$at, $statementEnd, $listEnd];
        }

        foreach ($facts['flows'] ?? [] as [$target, $term, $at]) {
            $flows[] = [$scopeKey, $target, $term, $at];
        }

        if (array_key_exists('call', $facts)) {
            $this->calls[$scopeKey][$facts['call'][0]] = [$facts['call'][1], $facts['call'][2]];

            foreach ($facts['call'][2] as $callee => $arguments) {
                foreach ($arguments as $parameter => $term) {
                    $binds[] = [$scopeKey, $callee, $parameter, $term];
                }
            }
        }

        if (array_key_exists('sink', $facts)) {
            [$method, $line, $term] = $facts['sink'];
            $sinks[$file][$line][$method][] = [$scopeKey, $term];
        }
    }

    /**
     * @param Term $term
     */
    private function flow(string $scopeKey, string $target, array $term, ?int $at): void
    {
        $value = $this->evaluate($term, $scopeKey);

        if ($target === 'r') {
            $this->returns[$scopeKey] = [
                $this->merged($this->returns[$scopeKey][0] ?? [], $value[0]),
                $this->merged($this->returns[$scopeKey][1] ?? [], $value[1]),
            ];

            return;
        }

        $half = $target[0];

        if ($target[1] === '|' && ($half === '*' || isset(self::LOCAL_SLOTS[$half]))) {
            $given = $at ?? self::UNKILLABLE;

            foreach ($half === '*' ? self::LOCAL_SLOTS : [self::LOCAL_SLOTS[$half]] as $prefix) {
                $slot = $scopeKey . '|' . $prefix . mb_substr($target, 2);
                $this->values[$slot][$given] = [
                    $this->merged($this->values[$slot][$given][0] ?? [], $value[0]),
                    $this->merged($this->values[$slot][$given][1] ?? [], $value[1]),
                ];
            }

            return;
        }

        $this->heap[$target] = $this->merged($this->heap[$target] ?? [], $this->concrete($value, $scopeKey));
    }

    /**
     * A term's value inside one scope: the labels it certainly carries, and the
     * scope's own parameters it depends on — resolved per call site by a
     * caller, and by every call site at once where the value leaves the call
     * (into a property, or into a cache key inside this very method).
     *
     * @param Term $term
     *
     * @return array{array<string, true>, array<string, true>}
     */
    private function evaluate(array $term, string $scopeKey): array
    {
        $labels = [];
        $parameters = [];

        foreach ($term as $atom) {
            [$kind, $name] = $atom;
            [$atomLabels, $atomParameters] = match ($kind) {
                's' => [$this->source($name), []],
                'h' => [$this->heap[$name] ?? [], []],
                'c' => $this->callValue($name, $scopeKey),
                default => $this->variable($scopeKey, self::LOCAL_SLOTS[$kind] . $name, $name, $atom[2] ?? null),
            };

            $labels += $atomLabels;
            $parameters += $atomParameters;
        }

        return [$labels, $parameters];
    }

    /**
     * @return array<string, true> the candidate's label when its model's resolved cast encrypts the attribute
     */
    private function source(string $candidate): array
    {
        [$model, $attribute, $readAt] = explode('|', $candidate, 3);

        $label = sprintf('%s::$%s (read at %s)', $model, $attribute, $readAt);

        if (in_array($attribute, $this->castReader->encryptedAttributesOf($model), true)) {
            return [$label => true];
        }

        return $this->castReader->castMapUnreadable($model) ? [self::UNVERIFIABLE . $label => true] : [];
    }

    /**
     * Every value the variable is given that can still hold at the read
     * offset; with no offset, every value it is ever given. A parameter's
     * own value is given at offset -1, before the body.
     *
     * @return array{array<string, true>, array<string, true>}
     */
    private function variable(string $scopeKey, string $slot, string $name, ?int $readAt): array
    {
        $labels = [];
        $parameters = [];

        foreach ($this->values[$scopeKey . '|' . $slot] ?? [] as $givenAt => [$givenLabels, $givenParameters]) {
            if (!$this->replaced($scopeKey, $name, $givenAt, $readAt)) {
                $labels += $givenLabels;
                $parameters += $givenParameters;
            }
        }

        if (isset($this->parameters[$scopeKey][$name]) && !$this->replaced($scopeKey, $name, -1, $readAt)) {
            $parameters[$name] = true;
        }

        return [$labels, $parameters];
    }

    /**
     * Whether a `$name = …;` statement stands between a value given at one
     * offset and a read at another: the read follows the statement in its
     * statement list, and the value was not given later in that list.
     */
    private function replaced(string $scopeKey, string $name, int|string $givenAt, ?int $readAt): bool
    {
        if ($readAt === null || $givenAt === self::UNKILLABLE) {
            return false;
        }

        foreach ($this->kills[$scopeKey][$name] ?? [] as [$at, $statementEnd, $listEnd]) {
            if ($statementEnd < $readAt && $readAt <= $listEnd && $givenAt !== $at && ($givenAt <= $statementEnd || $givenAt > $listEnd)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array{array<string, true>, array<string, true>}
     */
    private function callValue(string $callId, string $scopeKey): array
    {
        [$fallback, $callees] = $this->calls[$scopeKey][$callId] ?? [[], []];
        $labels = [];
        $parameters = [];
        $unanalysed = $callees === [];

        foreach ($callees as $callee => $arguments) {
            if (!array_key_exists($callee, $this->parameters)) {
                $unanalysed = true;

                continue;
            }

            [$returned, $dependsOn] = $this->returns[$callee] ?? [[], []];
            $labels += $returned;

            foreach (array_keys($dependsOn) as $parameter) {
                [$argumentLabels, $argumentParameters] = $this->evaluate($arguments[$parameter] ?? [], $scopeKey);
                $labels += $argumentLabels;
                $parameters += $argumentParameters;
            }
        }

        // A dispatch path with no collected body is read from its arguments, whatever the analysed paths return.
        if ($unanalysed) {
            [$fallbackLabels, $fallbackParameters] = $this->evaluate($fallback, $scopeKey);
            $labels += $fallbackLabels;
            $parameters += $fallbackParameters;
        }

        return [$labels, $parameters];
    }

    /**
     * @param array{array<string, true>, array<string, true>} $value
     *
     * @return array<string, true>
     */
    private function concrete(array $value, string $scopeKey): array
    {
        $labels = $value[0];

        foreach (array_keys($value[1]) as $parameter) {
            $labels += $this->bound[$scopeKey . '|' . $parameter] ?? [];
        }

        return $labels;
    }

    /**
     * @param array<string, true> $into
     * @param array<string, true> $from
     *
     * @return array<string, true>
     */
    private function merged(array $into, array $from): array
    {
        foreach (array_keys($from) as $label) {
            if (!array_key_exists($label, $into)) {
                $into[$label] = true;
                $this->changed = true;
            }
        }

        return $into;
    }

    /**
     * @param array<string, array<int, array<string, list<array{string, Term}>>>> $sinks
     *
     * @return list<IdentifierRuleError>
     */
    private function errors(array $sinks): array
    {
        $errors = [];

        foreach ($sinks as $file => $lines) {
            foreach ($lines as $line => $methods) {
                foreach ($methods as $method => $occurrences) {
                    $error = $this->error($file, $line, $method, $occurrences);

                    if ($error !== null) {
                        $errors[] = $error;
                    }
                }
            }
        }

        return $errors;
    }

    /**
     * @param list<array{string, Term}> $occurrences the sink's term once per scope it was analysed in
     */
    private function error(string $file, int $line, string $method, array $occurrences): ?IdentifierRuleError
    {
        $labels = [];

        foreach ($occurrences as [$scopeKey, $term]) {
            $labels += $this->concrete($this->evaluate($term, $scopeKey), $scopeKey);
        }

        $unverifiable = [];

        foreach (array_keys($labels) as $label) {
            if (str_starts_with($label, self::UNVERIFIABLE)) {
                unset($labels[$label]);
                $unverifiable[] = mb_substr($label, 1);
            }
        }

        if ($labels !== []) {
            ksort($labels);

            return RuleErrorBuilder::message(sprintf(
                "Cache key passed to %s() derives from the encrypted attribute %s. Key the cache by the owning entity's id (war-room Principle 10): a digest of the credential is still keyed by it, lands in the cache store, and outlives a rotation.",
                $method,
                implode(', ', array_keys($labels)),
            ))
                ->identifier(self::IDENTIFIER)
                ->file($file)
                ->line($line)
                ->build();
        }

        if ($unverifiable === []) {
            return null;
        }

        sort($unverifiable);

        return RuleErrorBuilder::message(sprintf(
            "Cache key passed to %s() derives from %s, an attribute of a model whose casts this rule cannot read, so whether it is encrypted is unknown. Restate the model's casts as literal string pairs, or suppress %s here if the key is known safe.",
            $method,
            implode(', ', $unverifiable),
            self::UNVERIFIABLE_IDENTIFIER,
        ))
            ->identifier(self::UNVERIFIABLE_IDENTIFIER)
            ->file($file)
            ->line($line)
            ->build();
    }
}
