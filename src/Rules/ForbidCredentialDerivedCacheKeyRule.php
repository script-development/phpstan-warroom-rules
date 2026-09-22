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
 * which stores its counters under the key it is handed. A call into analysed
 * code yields the callee's return SUMMARY for the arguments at that call site,
 * so a shared key helper handed an id at one site and the credential at another
 * reports only the second.
 *
 * False negatives, each by construction: a cast class other than the two above,
 * or a cast added at runtime (`mergeCasts()`); a model whose cast map the
 * credential-cast rule cannot read (it reports that itself); an attribute read
 * other than by a constant name — `toArray()`, `only()`, `getAttributes()`,
 * `$vault->{$name}`, `getAttribute($name)`; a cache handle or method PHPStan
 * cannot resolve — `app('cache')` without larastan, a `mixed` value, a dynamic
 * method name; a value computed inside a closure or arrow function; and a call
 * through an interface, an abstract method or a function, which resolves to its
 * arguments, so a credential the implementation reads for itself is not seen.
 *
 * False positives, each by construction: a property is shared by every instance
 * of its class, so a value object constructed from the credential anywhere
 * taints its methods everywhere; and a value returned by a call that was HANDED
 * the credential — a provider response fetched with the API key — carries the
 * credential, so a key built from a field of that response reports.
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

    /** A slot name no PHP variable can take, so `$r` never reads as the return value. */
    private const string RETURN_SLOT = '@return';

    /** @var array<string, array<string, true>> scope => parameter names, for every analysed method */
    private array $parameters = [];

    /** @var array<string, array<string, array{Term, array<string, array<string, Term>>}>> scope => call id => fallback term, callee => parameter => argument term */
    private array $calls = [];

    /** @var array<string, array{array<string, true>, array<string, true>}> `scope|variable` or `scope|@return` => labels, parameters */
    private array $values = [];

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

            foreach ($flows as [$scopeKey, $target, $term]) {
                $this->flow($scopeKey, $target, $term);
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
     * @param list<array{string, string, Term}>                                   $flows
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

        foreach ($facts['flows'] ?? [] as [$target, $term]) {
            $flows[] = [$scopeKey, $target, $term];
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
    private function flow(string $scopeKey, string $target, array $term): void
    {
        $value = $this->evaluate($term, $scopeKey);

        if (str_starts_with($target, 'v|') || $target === 'r') {
            $slot = $scopeKey . '|' . ($target === 'r' ? self::RETURN_SLOT : mb_substr($target, 2));
            $this->values[$slot] = [
                $this->merged($this->values[$slot][0] ?? [], $value[0]),
                $this->merged($this->values[$slot][1] ?? [], $value[1]),
            ];

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

        foreach ($term as [$kind, $name]) {
            [$atomLabels, $atomParameters] = match ($kind) {
                's' => [$this->source($name), []],
                'v' => $this->variable($scopeKey, $name),
                'h' => [$this->heap[$name] ?? [], []],
                default => $this->callValue($name, $scopeKey),
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

        if (!in_array($attribute, $this->castReader->encryptedAttributesOf($model), true)) {
            return [];
        }

        return [sprintf('%s::$%s (read at %s)', $model, $attribute, $readAt) => true];
    }

    /**
     * @return array{array<string, true>, array<string, true>}
     */
    private function variable(string $scopeKey, string $name): array
    {
        $value = $this->values[$scopeKey . '|' . $name] ?? [[], []];

        if (isset($this->parameters[$scopeKey][$name])) {
            $value[1][$name] = true;
        }

        return $value;
    }

    /**
     * @return array{array<string, true>, array<string, true>}
     */
    private function callValue(string $callId, string $scopeKey): array
    {
        [$fallback, $callees] = $this->calls[$scopeKey][$callId] ?? [[], []];
        $labels = [];
        $parameters = [];
        $resolved = false;

        foreach ($callees as $callee => $arguments) {
            if (!array_key_exists($callee, $this->parameters)) {
                continue;
            }

            $resolved = true;
            [$returned, $dependsOn] = $this->values[$callee . '|' . self::RETURN_SLOT] ?? [[], []];
            $labels += $returned;

            foreach (array_keys($dependsOn) as $parameter) {
                [$argumentLabels, $argumentParameters] = $this->evaluate($arguments[$parameter] ?? [], $scopeKey);
                $labels += $argumentLabels;
                $parameters += $argumentParameters;
            }
        }

        return $resolved ? [$labels, $parameters] : $this->evaluate($fallback, $scopeKey);
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

        if ($labels === []) {
            return null;
        }

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
}
