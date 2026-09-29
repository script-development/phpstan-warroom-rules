<?php

declare(strict_types = 1);

namespace ScriptDevelopment\PhpstanWarroomRules\Collectors;

use Illuminate\Cache\RateLimiter;
use Illuminate\Contracts\Cache\Store;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\RateLimiter as RateLimiterFacade;
use PhpParser\Node;
use PhpParser\Node\Arg;
use PhpParser\Node\ArrayItem;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\Array_;
use PhpParser\Node\Expr\ArrayDimFetch;
use PhpParser\Node\Expr\ArrowFunction;
use PhpParser\Node\Expr\Assign;
use PhpParser\Node\Expr\AssignOp;
use PhpParser\Node\Expr\AssignRef;
use PhpParser\Node\Expr\BinaryOp\Concat;
use PhpParser\Node\Expr\Closure;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Expr\List_;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\New_;
use PhpParser\Node\Expr\NullsafeMethodCall;
use PhpParser\Node\Expr\NullsafePropertyFetch;
use PhpParser\Node\Expr\PropertyFetch;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Expr\StaticPropertyFetch;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Identifier;
use PhpParser\Node\InterpolatedStringPart;
use PhpParser\Node\Name;
use PhpParser\Node\Scalar\InterpolatedString;
use PhpParser\Node\Scalar\String_;
use PhpParser\Node\Stmt;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\Stmt\Foreach_;
use PhpParser\Node\Stmt\Return_;
use PHPStan\Analyser\Scope;
use PHPStan\Collectors\Collector;
use PHPStan\Reflection\ClassReflection;
use PHPStan\Reflection\ExtendedMethodReflection;
use PHPStan\Reflection\MethodReflection;
use PHPStan\Reflection\ParameterReflection;
use PHPStan\Reflection\ReflectionProvider;
use PHPStan\Type\ObjectType;
use PHPStan\Type\Type;
use PHPStan\Type\TypeCombinator;

use function array_any;
use function array_filter;
use function array_map;
use function array_slice;
use function array_values;
use function basename;
use function count;
use function in_array;
use function is_array;
use function is_string;
use function mb_strtolower;
use function preg_match;
use function sprintf;
use function str_starts_with;

/**
 * Records, per node, the facts `ForbidCredentialDerivedCacheKeyRule` needs to
 * trace an encrypted attribute into a cache key across methods and classes: a
 * PHPStan rule sees one node, and the value object that hashes the credential
 * sits in a different file from the `get()` that uses it.
 *
 * A TERM is the set of things a value is computed from, as atoms:
 *
 *   - `s` — a CANDIDATE source, `Model|attribute|File.php:line`: a named
 *     attribute read on a receiver whose TYPE is an Eloquent model
 *     (`$vault->api_key`, `$vault['api_key']`, `$vault->getAttribute('api_key')`).
 *     Whether the attribute's cast encrypts it is decided by the rule on every
 *     run, never here: collected data is cached per file, and PHPStan does not
 *     re-analyse a file when only a method BODY it depends on changes, so a
 *     cast verdict stored here would go stale the day a `casts()` body moves.
 *   - `v` — a local variable (or parameter) of the enclosing method.
 *   - `k` — the keys a cache sink reads out of a local variable holding a
 *     map: its string keys, and the values of its other entries.
 *   - `h` — a HEAP slot: a declared property, per topmost declaring class,
 *     shared by every instance (`p|Class|name`), or a static property
 *     (`s|Class|name`).
 *   - `c` — the value of a call, resolved by the rule from the call's own fact:
 *     the callee's return summary when the callee is analysed code, otherwise
 *     everything its receiver and arguments are computed from.
 *
 * Everything else — operators, interpolation, casts, array literals, every
 * function — is the union of its children. A closure contributes nothing.
 *
 * A read on a MODEL receiver contributes only the attribute it names, never the
 * model object as a whole: a model is an entity, and the credential is one
 * column of it, so `$vault->id` stays clean even when `$vault` itself was
 * looked up by its API key.
 *
 * @phpstan-type Atom array{string, string}
 * @phpstan-type Term list<Atom>
 * @phpstan-type Facts array{
 *     scope: string,
 *     params?: list<string>,
 *     flows?: list<array{string, Term}>,
 *     call?: array{string, Term, array<string, array<string, Term>>},
 *     sink?: array{string, int, Term},
 * }
 *
 * @implements Collector<Node, Facts>
 */
final class CacheKeyTaintCollector implements Collector
{
    /**
     * Every cache method that takes a key: the parameter it arrives in, always
     * the first, and how Laravel reads keys out of that argument — `one` it is
     * the key; `map` it is a key or an array whose string keys are keys and
     * whose other entries are keyed by their value (`many()`, and `put()` /
     * `putMany()` read at least that much); `values` the keys are the values
     * (`getMultiple()`, `deleteMultiple()`); `all` every argument names a tag.
     */
    private const array CACHE_SINKS = [
        'add' => ['key', 'one'],
        'array' => ['key', 'one'],
        'boolean' => ['key', 'one'],
        'decrement' => ['key', 'one'],
        'delete' => ['key', 'one'],
        'deletemultiple' => ['keys', 'values'],
        'flexible' => ['key', 'one'],
        'float' => ['key', 'one'],
        'forever' => ['key', 'one'],
        'forget' => ['key', 'one'],
        'funnel' => ['name', 'one'],
        'get' => ['key', 'map'],
        'getmultiple' => ['keys', 'values'],
        'has' => ['key', 'one'],
        'increment' => ['key', 'one'],
        'integer' => ['key', 'one'],
        'lock' => ['name', 'one'],
        'many' => ['keys', 'map'],
        'missing' => ['key', 'one'],
        'pull' => ['key', 'one'],
        'put' => ['key', 'map'],
        'putmany' => ['values', 'map'],
        'remember' => ['key', 'one'],
        'rememberforever' => ['key', 'one'],
        'rememberwithwarmth' => ['key', 'one'],
        'restorelock' => ['name', 'one'],
        'sear' => ['key', 'one'],
        'set' => ['key', 'map'],
        'setmultiple' => ['values', 'map'],
        'string' => ['key', 'one'],
        'tags' => ['names', 'all'],
        'touch' => ['key', 'one'],
        'withoutoverlapping' => ['key', 'one'],
    ];

    /** `RateLimiter` methods, each of which stores its counters under the key in its first parameter. */
    private const array RATE_LIMITER_SINKS = [
        'attempt' => ['key', 'one'],
        'attempts' => ['key', 'one'],
        'availablein' => ['key', 'one'],
        'clear' => ['key', 'one'],
        'decrement' => ['key', 'one'],
        'hit' => ['key', 'one'],
        'increment' => ['key', 'one'],
        'remaining' => ['key', 'one'],
        'resetattempts' => ['key', 'one'],
        'retriesleft' => ['key', 'one'],
        'toomanyattempts' => ['key', 'one'],
    ];

    private const array CACHE_NAMESPACES = ['Illuminate\Contracts\Cache\\', 'Illuminate\Cache\\'];

    private const array ATTRIBUTE_READERS = ['getattribute', 'getattributevalue', 'getoriginal', 'getraworiginal'];

    public function __construct(
        private ReflectionProvider $reflectionProvider,
    ) {}

    public function getNodeType(): string
    {
        return Node::class;
    }

    /**
     * @return Facts|null
     */
    public function processNode(Node $node, Scope $scope): ?array
    {
        if ($node instanceof ClassMethod) {
            return $this->method($node, $scope);
        }

        $key = $this->scopeKey($scope);

        if ($node instanceof Assign || $node instanceof AssignOp) {
            $term = $this->term($node->expr, $scope);

            return $this->flows($key, $this->assignment($node->var, $term, $node->expr instanceof Array_ ? $this->mapKeys($node->expr, $scope) : $term, $scope));
        }

        if ($node instanceof AssignRef) {
            // A reference is written through in both directions.
            $into = $this->term($node->expr, $scope);
            $back = $this->term($node->var, $scope);

            return $this->flows($key, [...$this->assignment($node->var, $into, $into, $scope), ...$this->assignment($node->expr, $back, $back, $scope)]);
        }

        if ($node instanceof Foreach_) {
            $term = $this->term($node->expr, $scope);
            $flows = $this->assignment($node->valueVar, $term, $term, $scope);

            if ($node->keyVar instanceof Expr) {
                $flows = [...$flows, ...$this->assignment($node->keyVar, $term, $term, $scope)];
            }

            if ($node->byRef) {
                $back = $this->term($node->valueVar, $scope);
                $flows = [...$flows, ...$this->assignment($node->expr, $back, $back, $scope)];
            }

            return $this->flows($key, $flows);
        }

        if ($node instanceof Return_ && $node->expr instanceof Expr && !$scope->isInAnonymousFunction()) {
            return $this->flows($key, [['r', $this->term($node->expr, $scope)]]);
        }

        if ($node instanceof MethodCall || $node instanceof NullsafeMethodCall || $node instanceof StaticCall || $node instanceof New_ || $node instanceof FuncCall) {
            return $this->call($node, $key, $scope);
        }

        return null;
    }

    /**
     * A method with a body is analysed code: calls to it resolve through its
     * return summary instead of through their arguments. A promoted
     * constructor parameter flows into its property.
     *
     * @return Facts|null
     */
    private function method(ClassMethod $node, Scope $scope): ?array
    {
        $class = $scope->getClassReflection();

        if ($node->stmts === null || $class === null) {
            return null;
        }

        $key = $class->getName() . '::' . $node->name->toLowerString();
        $params = [];
        $flows = [];

        foreach ($node->params as $param) {
            if (!$param->var instanceof Variable || !is_string($param->var->name)) {
                continue;
            }

            $params[] = $param->var->name;

            if ($param->isPromoted() && $class->hasNativeProperty($param->var->name)) {
                $flows[] = ['p|' . $this->propertyOwner($class, $param->var->name) . '|' . $param->var->name, [['v', $param->var->name]]];
            }
        }

        return ['scope' => $key, 'params' => $params, 'flows' => $flows];
    }

    /**
     * @param list<array{string, Term}> $flows
     *
     * @return Facts|null
     */
    private function flows(string $scope, array $flows): ?array
    {
        $flows = array_values(array_filter($flows, static fn(array $flow): bool => $flow[1] !== []));

        return $flows === [] ? null : ['scope' => $scope, 'flows' => $flows];
    }

    /**
     * Where an assignment's value lands. A local variable is two slots: its
     * value (`v|name`), and the keys a cache sink reads out of it when it holds
     * a map (`k|name`) — so a map hoisted into a variable keeps its keys apart
     * from its values. `$map[$key] = …` lands in the array holding it, key
     * included; a destructuring assignment lands in every element; a property
     * lands in its heap slot, or — when the receiver's type declares none — in
     * the receiver itself.
     *
     * @param Term $term the value assigned
     * @param Term $keys the keys a cache sink reads out of that value
     *
     * @return list<array{string, Term}>
     */
    private function assignment(Expr $target, array $term, array $keys, Scope $scope): array
    {
        if ($target instanceof Variable) {
            return is_string($target->name) ? [['v|' . $target->name, $term], ['k|' . $target->name, $keys]] : [];
        }

        if ($target instanceof ArrayDimFetch) {
            return $this->assignment($target->var, [...$this->dimTerm($target, $scope), ...$term], $this->entryKey($target->dim, $term, $scope), $scope);
        }

        if ($target instanceof List_ || $target instanceof Array_) {
            $flows = [];

            foreach ($target->items as $item) {
                if ($item !== null) {
                    $flows = [...$flows, ...$this->assignment($item->value, $term, $term, $scope)];
                }
            }

            return $flows;
        }

        if ($target instanceof PropertyFetch || $target instanceof StaticPropertyFetch) {
            $slots = $this->heapKeys($target, $scope);

            if ($slots === [] && $target instanceof PropertyFetch && $target->name instanceof Identifier) {
                return $this->assignment($target->var, $term, $term, $scope);
            }

            return array_map(static fn(string $slot): array => [$slot, $term], $slots);
        }

        return [];
    }

    /**
     * @return Term
     */
    private function dimTerm(ArrayDimFetch $fetch, Scope $scope): array
    {
        return $fetch->dim instanceof Expr ? $this->term($fetch->dim, $scope) : [];
    }

    /**
     * @return Facts
     */
    private function call(FuncCall|MethodCall|New_|NullsafeMethodCall|StaticCall $node, string $scope, Scope $nodeScope): array
    {
        $arguments = $node->isFirstClassCallable() ? [] : $node->getArgs();
        $callees = [];

        foreach ($this->callees($node, $nodeScope) as $callee => $method) {
            $callees[$callee] = $this->argumentsByParameter($method, $arguments, $nodeScope);
        }

        $fallback = $this->union(array_map(static fn(Arg $argument): Expr => $argument->value, $arguments), $nodeScope);
        $facts = ['scope' => $scope, 'call' => [$this->callId($node), $fallback, $callees]];
        $sink = $this->sink($node, $nodeScope);

        if ($sink !== null) {
            $facts['sink'] = [$sink[0], $node->getStartLine(), $sink[1]];
        }

        return $facts;
    }

    /**
     * @return array<string, ExtendedMethodReflection> `Class::method` of every method this call may dispatch to
     */
    private function callees(FuncCall|MethodCall|New_|NullsafeMethodCall|StaticCall $node, Scope $scope): array
    {
        if ($node instanceof FuncCall) {
            return [];
        }

        if ($node instanceof New_) {
            $classes = $node->class instanceof Name ? $this->namedClasses($node->class, $scope) : [];
            $name = '__construct';
        } else {
            if (!$node->name instanceof Identifier) {
                return [];
            }

            $name = $node->name->toString();
            $classes = $node instanceof StaticCall
                ? ($node->class instanceof Name ? $this->namedClasses($node->class, $scope) : [])
                : $scope->getType($node->var)->getObjectClassReflections();
        }

        $callees = [];

        foreach ($classes as $class) {
            if (!$class->hasMethod($name)) {
                continue;
            }

            $method = $class->getMethod($name, $scope);
            $callees[$method->getDeclaringClass()->getName() . '::' . mb_strtolower($method->getName())] = $method;
        }

        return $callees;
    }

    /**
     * @return list<ClassReflection>
     */
    private function namedClasses(Name $name, Scope $scope): array
    {
        $resolved = $scope->resolveName($name);

        return $this->reflectionProvider->hasClass($resolved) ? [$this->reflectionProvider->getClass($resolved)] : [];
    }

    /**
     * @param array<Arg> $arguments
     *
     * @return array<string, Term> the term each argument hands to the parameter it binds
     */
    private function argumentsByParameter(MethodReflection $method, array $arguments, Scope $scope): array
    {
        $parameters = $method->getVariants()[0]->getParameters();
        $last = $parameters[count($parameters) - 1] ?? null;
        $overflow = $last?->isVariadic() === true ? [$last] : [];
        $bound = [];

        foreach ($arguments as $position => $argument) {
            $name = $argument->name?->toString();

            if ($name !== null) {
                $receiving = array_filter($parameters, static fn(ParameterReflection $parameter): bool => $parameter->getName() === $name);
            } elseif ($argument->unpack) {
                $receiving = array_slice($parameters, $position);
            } else {
                $receiving = isset($parameters[$position]) ? [$parameters[$position]] : $overflow;
            }

            foreach ($receiving as $parameter) {
                $bound[$parameter->getName()] = [...$bound[$parameter->getName()] ?? [], ...$this->term($argument->value, $scope)];
            }
        }

        return $bound;
    }

    /**
     * The cache operation this call is and the term of the key it is handed,
     * or null when it is not a cache operation.
     *
     * @return array{string, Term}|null
     */
    private function sink(FuncCall|MethodCall|New_|NullsafeMethodCall|StaticCall $node, Scope $scope): ?array
    {
        if ($node instanceof New_ || $node->isFirstClassCallable()) {
            return null;
        }

        $arguments = $node->getArgs();

        if ($arguments === []) {
            return null;
        }

        if ($node instanceof FuncCall) {
            return $node->name instanceof Name && $this->isCacheHelper($node->name, $scope)
                ? ['cache', $this->keyTerm($arguments, 'key', 'map', $scope)]
                : null;
        }

        if (!$node->name instanceof Identifier) {
            return null;
        }

        $method = $node->name->toString();
        $kind = $this->handleKind($node, $scope);
        $sinks = match ($kind) {
            'cache', 'store' => self::CACHE_SINKS,
            'rateLimiter' => self::RATE_LIMITER_SINKS,
            default => [],
        };
        $lower = mb_strtolower($method);
        $sink = $sinks[$lower] ?? null;

        if ($sink === null) {
            return null;
        }

        [$parameter, $reads] = $sink;

        // `Store::many()` keys by the array's values, `Repository::many()` by its string keys.
        return [$method, $this->keyTerm($arguments, $parameter, $kind === 'store' && $lower === 'many' ? 'all' : $reads, $scope)];
    }

    /**
     * The term of the keys a sink's arguments name: the argument PHP binds to
     * the key parameter, read the way Laravel reads keys out of it. When no
     * argument can be placed on that parameter — a spread covers it, or no
     * argument names it — every argument counts.
     *
     * @param array<Arg> $arguments
     *
     * @return Term
     */
    private function keyTerm(array $arguments, string $parameter, string $reads, Scope $scope): array
    {
        $key = $reads === 'all' ? null : $this->boundArgument($arguments, $parameter);

        if ($key === null) {
            return $this->union(array_map(static fn(Arg $argument): Expr => $argument->value, $arguments), $scope);
        }

        if ($reads === 'values' && $key instanceof Array_) {
            return $this->union(array_map(static fn(ArrayItem $item): Expr => $item->value, $key->items), $scope);
        }

        if ($reads === 'map' && $key instanceof Array_) {
            return $this->mapKeys($key, $scope);
        }

        if ($reads === 'map' && $key instanceof Variable && is_string($key->name)) {
            return [['k', $key->name]];
        }

        return $this->term($key, $scope);
    }

    /**
     * The argument PHP binds to a sink's first parameter, or null when that
     * cannot be told: a spread in first position, or no argument naming it.
     *
     * @param array<Arg> $arguments
     */
    private function boundArgument(array $arguments, string $parameter): ?Expr
    {
        foreach ($arguments as $argument) {
            if ($argument->name === null) {
                return $argument->unpack ? null : $argument->value;
            }

            if ($argument->name->toString() === $parameter) {
                return $argument->value;
            }
        }

        return null;
    }

    /**
     * The keys Laravel reads out of an array literal: a string key names its
     * entry; any other entry — no key, or a key PHP may store as an integer
     * (it turns `'7'` into `7`) — may be keyed by its value, as `many()` does.
     *
     * @return Term
     */
    private function mapKeys(Array_ $map, Scope $scope): array
    {
        $term = [];

        foreach ($map->items as $item) {
            $term = [...$term, ...$this->entryKey($item->key, $this->term($item->value, $scope), $scope)];
        }

        return $term;
    }

    /**
     * @param Term $value
     *
     * @return Term
     */
    private function entryKey(?Expr $key, array $value, Scope $scope): array
    {
        if ($key === null) {
            return $value;
        }

        return $this->mayBeIntegerKey($key, $scope) ? [...$this->term($key, $scope), ...$value] : $this->term($key, $scope);
    }

    private function mayBeIntegerKey(Expr $key, Scope $scope): bool
    {
        $type = $scope->getType($key);

        if (!$type->isString()->yes()) {
            return true;
        }

        return !$type->isNumericString()->no() && !$this->holdsNonDigit($key);
    }

    /**
     * Whether a string expression carries a literal character no decimal
     * integer holds — `'vault:' . $id` cannot become an integer key, though
     * PHPStan types it as a string that may be numeric.
     */
    private function holdsNonDigit(Expr $expr): bool
    {
        if ($expr instanceof Concat) {
            return $this->holdsNonDigit($expr->left) || $this->holdsNonDigit($expr->right);
        }

        if ($expr instanceof InterpolatedString) {
            return array_any($expr->parts, fn(Expr|InterpolatedStringPart $part): bool => $part instanceof InterpolatedStringPart ? preg_match('/[^0-9-]/', $part->value) === 1 : $this->holdsNonDigit($part));
        }

        return $expr instanceof String_ && preg_match('/[^0-9-]/', $expr->value) === 1;
    }

    /**
     * `cache` when the receiver is a cache handle — anything typed in the
     * `Illuminate\Contracts\Cache` / `Illuminate\Cache` namespaces or deriving
     * from one, or the `Cache` facade — `store` when that handle may be a raw
     * cache `Store`, and `rateLimiter` for Laravel's rate limiter, which stores
     * its counters under the key it is handed.
     *
     * @return 'cache'|'rateLimiter'|'store'|null
     */
    private function handleKind(MethodCall|NullsafeMethodCall|StaticCall $node, Scope $scope): ?string
    {
        if ($node instanceof StaticCall) {
            if (!$node->class instanceof Name) {
                return null;
            }

            return match ($scope->resolveName($node->class)) {
                Cache::class => 'cache',
                RateLimiterFacade::class => 'rateLimiter',
                default => null,
            };
        }

        $classes = $scope->getType($node->var)->getObjectClassReflections();

        if (array_any($classes, static fn(ClassReflection $class): bool => $class->is(RateLimiter::class))) {
            return 'rateLimiter';
        }

        if (!array_any($classes, fn(ClassReflection $class): bool => $this->isCacheClass($class))) {
            return null;
        }

        return array_any($classes, static fn(ClassReflection $class): bool => $class->is(Store::class)) ? 'store' : 'cache';
    }

    private function isCacheClass(ClassReflection $class): bool
    {
        foreach ([$class, ...$class->getParents(), ...$class->getInterfaces()] as $candidate) {
            if (array_any(self::CACHE_NAMESPACES, static fn(string $namespace): bool => str_starts_with($candidate->getName(), $namespace))) {
                return true;
            }
        }

        return false;
    }

    private function isCacheHelper(Name $name, Scope $scope): bool
    {
        return mb_strtolower($this->reflectionProvider->resolveFunctionName($name, $scope) ?? $name->toString()) === 'cache';
    }

    /**
     * @return Term
     */
    private function term(Expr $expr, Scope $scope): array
    {
        if ($expr instanceof Variable) {
            return is_string($expr->name) && $expr->name !== 'this' ? [['v', $expr->name]] : [];
        }

        if ($expr instanceof Closure || $expr instanceof ArrowFunction) {
            return [];
        }

        if ($expr instanceof PropertyFetch || $expr instanceof NullsafePropertyFetch) {
            if ($expr->name instanceof Identifier && $this->isModel($expr->var, $scope)) {
                return $this->sources($expr->var, $expr->name->toString(), $expr, $scope);
            }

            return [...$this->heapAtoms($this->heapKeys($expr, $scope)), ...$this->term($expr->var, $scope)];
        }

        if ($expr instanceof StaticPropertyFetch) {
            return $this->heapAtoms($this->heapKeys($expr, $scope));
        }

        if ($expr instanceof ArrayDimFetch && $expr->dim instanceof Expr && $this->isModel($expr->var, $scope)) {
            $attributes = $scope->getType($expr->dim)->getConstantStrings();

            return count($attributes) === 1 ? $this->sources($expr->var, $attributes[0]->getValue(), $expr, $scope) : [];
        }

        if ($expr instanceof MethodCall || $expr instanceof NullsafeMethodCall || $expr instanceof StaticCall || $expr instanceof New_ || $expr instanceof FuncCall) {
            return $this->callTerm($expr, $scope);
        }

        return $this->union($this->childExpressions($expr), $scope);
    }

    /**
     * @param array<Expr> $expressions
     *
     * @return Term
     */
    private function union(array $expressions, Scope $scope): array
    {
        $term = [];

        foreach ($expressions as $expression) {
            $term = [...$term, ...$this->term($expression, $scope)];
        }

        return $term;
    }

    /**
     * @return Term
     */
    private function callTerm(FuncCall|MethodCall|New_|NullsafeMethodCall|StaticCall $expr, Scope $scope): array
    {
        if ($expr instanceof New_) {
            return $this->union(array_map(static fn(Arg $argument): Expr => $argument->value, $expr->getArgs()), $scope);
        }

        $term = [['c', $this->callId($expr)]];

        if (!$expr instanceof MethodCall && !$expr instanceof NullsafeMethodCall) {
            return $term;
        }

        if (!$this->isModel($expr->var, $scope)) {
            return [...$term, ...$this->term($expr->var, $scope)];
        }

        $arguments = $expr->isFirstClassCallable() ? [] : $expr->getArgs();

        if (
            !$expr->name instanceof Identifier
            || !in_array($expr->name->toLowerString(), self::ATTRIBUTE_READERS, true)
            || $arguments === []
        ) {
            return $term;
        }

        $attributes = $scope->getType($arguments[0]->value)->getConstantStrings();

        return count($attributes) === 1 ? [...$term, ...$this->sources($expr->var, $attributes[0]->getValue(), $expr, $scope)] : $term;
    }

    /**
     * @return Term one candidate source per model the receiver may be
     */
    private function sources(Expr $receiver, string $attribute, Expr $read, Scope $scope): array
    {
        $sources = [];

        foreach (TypeCombinator::removeNull($scope->getType($receiver))->getObjectClassNames() as $model) {
            $sources[] = ['s', sprintf('%s|%s|%s:%d', $model, $attribute, basename($scope->getFile()), $read->getStartLine())];
        }

        return $sources;
    }

    private function isModel(Expr $receiver, Scope $scope): bool
    {
        $type = TypeCombinator::removeNull($scope->getType($receiver));

        return $type->getObjectClassNames() !== [] && new ObjectType(Model::class)->isSuperTypeOf($type)->yes();
    }

    /**
     * The heap slots a property fetch names: `p|DeclaringClass|name` for each
     * class the receiver may be that declares the property natively, and
     * `s|DeclaringClass|name` for a static property.
     *
     * @return list<string>
     */
    private function heapKeys(NullsafePropertyFetch|PropertyFetch|StaticPropertyFetch $fetch, Scope $scope): array
    {
        if ($fetch instanceof StaticPropertyFetch) {
            if (!$fetch->class instanceof Name || !$fetch->name instanceof Node\VarLikeIdentifier) {
                return [];
            }

            $classes = $this->namedClasses($fetch->class, $scope);
            $prefix = 's|';
        } else {
            if (!$fetch->name instanceof Identifier) {
                return [];
            }

            $classes = $scope->getType($fetch->var)->getObjectClassReflections();
            $prefix = 'p|';
        }

        $name = $fetch->name->toString();
        $keys = [];

        foreach ($classes as $class) {
            if ($class->hasNativeProperty($name)) {
                $keys[] = $prefix . $this->propertyOwner($class, $name) . '|' . $name;
            }
        }

        return $keys;
    }

    /**
     * The topmost class declaring a property, so a subclass that redeclares
     * it shares the slot a read through the parent's type looks in.
     */
    private function propertyOwner(ClassReflection $class, string $name): string
    {
        $owner = $class->getNativeProperty($name)->getDeclaringClass();

        while (($parent = $owner->getParentClass()) !== null && $parent->hasNativeProperty($name)) {
            $owner = $parent->getNativeProperty($name)->getDeclaringClass();
        }

        return $owner->getName();
    }

    /**
     * @param list<string> $keys
     *
     * @return Term
     */
    private function heapAtoms(array $keys): array
    {
        return array_map(static fn(string $key): array => ['h', $key], $keys);
    }

    /**
     * @return list<Expr> the expressions directly inside a node, through Arg / ArrayItem / MatchArm wrappers
     */
    private function childExpressions(Node $node): array
    {
        $found = [];

        foreach ($node->getSubNodeNames() as $subNodeName) {
            $children = $node->{$subNodeName};

            foreach (is_array($children) ? $children : [$children] as $child) {
                if ($child instanceof Expr) {
                    $found[] = $child;
                } elseif ($child instanceof Node && !$child instanceof Stmt) {
                    $found = [...$found, ...$this->childExpressions($child)];
                }
            }
        }

        return $found;
    }

    private function scopeKey(Scope $scope): string
    {
        $function = $scope->getFunction();

        if ($function instanceof MethodReflection) {
            return $function->getDeclaringClass()->getName() . '::' . mb_strtolower($function->getName());
        }

        return $function !== null ? $function->getName() : $scope->getFile();
    }

    /**
     * Unique within one scope, which is all a term needs: it is always
     * resolved in the scope it was built in. Start AND end offset, because
     * every call in a fluent chain starts where the chain starts —
     * `new Stringable($x)->prepend('a')->toString()` holds three calls at one
     * start offset.
     */
    private function callId(Node $node): string
    {
        return $node->getStartFilePos() . '-' . $node->getEndFilePos();
    }
}
