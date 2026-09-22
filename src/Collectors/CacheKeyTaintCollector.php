<?php

declare(strict_types = 1);

namespace ScriptDevelopment\PhpstanWarroomRules\Collectors;

use Illuminate\Cache\RateLimiter;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\RateLimiter as RateLimiterFacade;
use PhpParser\Node;
use PhpParser\Node\Arg;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\ArrayDimFetch;
use PhpParser\Node\Expr\ArrowFunction;
use PhpParser\Node\Expr\Assign;
use PhpParser\Node\Expr\AssignOp;
use PhpParser\Node\Expr\AssignRef;
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
use PhpParser\Node\Name;
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
use function basename;
use function count;
use function in_array;
use function is_array;
use function is_string;
use function mb_strtolower;
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
 *   - `h` — a HEAP slot: a declared property, per declaring class, shared by
 *     every instance (`p|Class|name`), or a static property (`s|Class|name`).
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
    /** Cache methods whose first argument is one key. */
    private const array KEY_METHODS = [
        'add', 'decrement', 'delete', 'flexible', 'forever', 'forget', 'get', 'has', 'increment', 'lock', 'missing',
        'pull', 'remember', 'rememberforever', 'restorelock', 'sear', 'set', 'touch',
    ];

    /**
     * Cache methods whose first argument is a list of keys or a map keyed by
     * them (`many(['key' => $default])`, `putMany(['key' => $value])`). `put`
     * takes either one key or such a map.
     */
    private const array KEY_COLLECTION_METHODS = ['deletemultiple', 'getmultiple', 'many', 'put', 'putmany', 'setmany'];

    /** Cache methods whose every argument names a tag. */
    private const array TAG_METHODS = ['tags'];

    /** `RateLimiter` methods whose first argument is the cache key the limiter stores under. */
    private const array RATE_LIMITER_METHODS = [
        'attempt', 'attempts', 'availablein', 'clear', 'decrement', 'hit', 'increment', 'remaining', 'resetattempts',
        'retriesleft', 'toomanyattempts',
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

        if ($node instanceof Assign || $node instanceof AssignRef || $node instanceof AssignOp) {
            return $this->flows($key, $this->targets($node->var, $scope), $this->term($node->expr, $scope));
        }

        if ($node instanceof Foreach_) {
            $targets = $this->targets($node->valueVar, $scope);

            if ($node->keyVar instanceof Expr) {
                $targets = [...$targets, ...$this->targets($node->keyVar, $scope)];
            }

            return $this->flows($key, $targets, $this->term($node->expr, $scope));
        }

        if ($node instanceof Return_ && $node->expr instanceof Expr && !$scope->isInAnonymousFunction()) {
            return $this->flows($key, ['r'], $this->term($node->expr, $scope));
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

            if ($param->isPromoted()) {
                $flows[] = ['p|' . $class->getName() . '|' . $param->var->name, [['v', $param->var->name]]];
            }
        }

        return ['scope' => $key, 'params' => $params, 'flows' => $flows];
    }

    /**
     * @param list<string> $targets
     * @param Term         $term
     *
     * @return Facts|null
     */
    private function flows(string $scope, array $targets, array $term): ?array
    {
        if ($targets === [] || $term === []) {
            return null;
        }

        return ['scope' => $scope, 'flows' => array_map(static fn(string $target): array => [$target, $term], $targets)];
    }

    /**
     * Where an assignment's value lands: a local variable (`v|name`), a heap
     * slot, or — through `$parts[] = …` — the variable or property holding the
     * array. A destructuring assignment lands in every element.
     *
     * @return list<string>
     */
    private function targets(Expr $target, Scope $scope): array
    {
        if ($target instanceof Variable) {
            return is_string($target->name) ? ['v|' . $target->name] : [];
        }

        if ($target instanceof ArrayDimFetch) {
            return $this->targets($target->var, $scope);
        }

        if ($target instanceof List_ || $target instanceof Expr\Array_) {
            $targets = [];

            foreach ($target->items as $item) {
                if ($item !== null) {
                    $targets = [...$targets, ...$this->targets($item->value, $scope)];
                }
            }

            return $targets;
        }

        return $target instanceof PropertyFetch || $target instanceof StaticPropertyFetch ? $this->heapKeys($target, $scope) : [];
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

        if ($node instanceof FuncCall) {
            if (!$node->name instanceof Name || !$this->isCacheHelper($node->name, $scope) || $arguments === []) {
                return null;
            }

            return ['cache', $this->keyTerm($arguments[0]->value, true, $scope)];
        }

        if (!$node->name instanceof Identifier) {
            return null;
        }

        $method = $node->name->toString();
        $lower = mb_strtolower($method);
        $kind = $this->handleKind($node, $scope);

        if ($kind === null) {
            return null;
        }

        if ($kind === 'cache' && in_array($lower, self::TAG_METHODS, true)) {
            return [$method, $this->union(array_map(static fn(Arg $argument): Expr => $argument->value, $arguments), $scope)];
        }

        $keyMethods = $kind === 'cache' ? [...self::KEY_METHODS, ...self::KEY_COLLECTION_METHODS] : self::RATE_LIMITER_METHODS;

        if ($arguments === [] || !in_array($lower, $keyMethods, true)) {
            return null;
        }

        return [$method, $this->keyTerm($arguments[0]->value, $kind === 'cache' && !in_array($lower, self::KEY_METHODS, true), $scope)];
    }

    /**
     * A literal `key => value` map contributes its keys only: the VALUE of a
     * cache entry is not its key.
     *
     * @return Term
     */
    private function keyTerm(Expr $key, bool $mapAllowed, Scope $scope): array
    {
        if (!$mapAllowed || !$key instanceof Expr\Array_) {
            return $this->term($key, $scope);
        }

        return $this->union(array_map(static fn(Node\ArrayItem $item): Expr => $item->key ?? $item->value, $key->items), $scope);
    }

    /**
     * `cache` when the receiver is a cache handle — anything typed in the
     * `Illuminate\Contracts\Cache` / `Illuminate\Cache` namespaces or deriving
     * from one, or the `Cache` facade — and `rateLimiter` for Laravel's rate
     * limiter, which stores its counters under the key it is handed.
     *
     * @return 'cache'|'rateLimiter'|null
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

        return array_any($classes, fn(ClassReflection $class): bool => $this->isCacheClass($class)) ? 'cache' : null;
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
                $keys[] = $prefix . $class->getNativeProperty($name)->getDeclaringClass()->getName() . '|' . $name;
            }
        }

        return $keys;
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
