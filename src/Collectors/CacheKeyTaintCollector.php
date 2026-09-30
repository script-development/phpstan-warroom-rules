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
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\Array_;
use PhpParser\Node\Expr\ArrayDimFetch;
use PhpParser\Node\Expr\ArrowFunction;
use PhpParser\Node\Expr\Assign;
use PhpParser\Node\Expr\AssignOp;
use PhpParser\Node\Expr\AssignRef;
use PhpParser\Node\Expr\BinaryOp\Coalesce;
use PhpParser\Node\Expr\BinaryOp\Concat;
use PhpParser\Node\Expr\BinaryOp\Plus;
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
use PhpParser\Node\Expr\Ternary;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Identifier;
use PhpParser\Node\InterpolatedStringPart;
use PhpParser\Node\Name;
use PhpParser\Node\Scalar\InterpolatedString;
use PhpParser\Node\Scalar\String_;
use PhpParser\Node\Stmt;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\Stmt\Expression;
use PhpParser\Node\Stmt\Foreach_;
use PhpParser\Node\Stmt\Goto_;
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
use function is_int;
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
 *   - `v` — a local variable (or parameter) of the enclosing method, with the
 *     offset it is read at; a read with no offset sees every value the
 *     variable is ever given.
 *   - `k`, `e`, `i` — the other halves of a local variable holding a map,
 *     read at an offset like `v`: the keys a cache sink reads out of it (its
 *     string keys, and the values of its other entries), its values, and its
 *     own keys. A flow into `*|name` is a value whose halves are one term.
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
 * A flow into a local carries the offset of the assignment making it, or null
 * when a later write elsewhere can still change the value — a reference, or
 * code inside a closure. A KILL is a `$name = …;` statement: a read after it
 * in the same statement list sees no value given outside the stretch between
 * them, since a list is only ever entered at its start. A method holding a
 * `goto` records no kills — a jump can land past one.
 *
 * @phpstan-type Atom array{0: string, 1: string, 2?: int}
 * @phpstan-type Term list<Atom>
 * @phpstan-type Facts array{
 *     scope: string,
 *     params?: list<string>,
 *     kills?: list<array{string, int, int, int}>,
 *     flows?: list<array{string, Term, int|null}>,
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

    /** A local's slots, in `halves()` order: whole value, sink-read keys, values, own keys. */
    private const array SLOTS = ['v', 'k', 'e', 'i'];

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
            return $this->flows($key, $this->assignment($node->var, $this->halves($node->expr, $scope), $scope), $this->definedAt($node, $scope));
        }

        if ($node instanceof AssignRef) {
            // A reference is written through in both directions, whenever either side is written.
            return $this->flows($key, [...$this->assignment($node->var, $this->halves($node->expr, $scope), $scope), ...$this->assignment($node->expr, $this->halves($node->var, $scope), $scope)], null);
        }

        if ($node instanceof Foreach_) {
            [, , $values, $index] = $this->halves($node->expr, $scope);
            $flows = $this->assignment($node->valueVar, [$values, $values, $values, $values], $scope);

            if ($node->keyVar instanceof Expr) {
                $flows = [...$flows, ...$this->assignment($node->keyVar, [$index, $index, $index, $index], $scope)];
            }

            $flows = $this->placed($flows, $this->definedAt($node, $scope));

            if ($node->byRef) {
                $back = $this->term($node->valueVar, $scope);
                $flows = [...$flows, ...$this->placed($this->assignment($node->expr, [$back, $back, $back, $back], $scope), null)];
            }

            return $this->placedFlows($key, $flows);
        }

        if ($node instanceof Return_ && $node->expr instanceof Expr && !$scope->isInAnonymousFunction()) {
            return $this->flows($key, [['r', $this->term($node->expr, $scope)]], null);
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
                $flows[] = ['p|' . $this->propertyOwner($class, $param->var->name) . '|' . $param->var->name, [['v', $param->var->name]], null];
            }
        }

        $kills = [];

        if (!$this->contains($node->stmts, Goto_::class)) {
            $this->kills($node, $kills);
        }

        return ['scope' => $key, 'params' => $params, 'kills' => $kills, 'flows' => $flows];
    }

    /**
     * Every `$name = …;` statement in a statement list of this code: the
     * variable, the assignment's offset, the statement's end and the list's
     * end. A list inside a closure or a nested declaration is entered at its
     * start like any other, and its reads resolve in their own scope.
     *
     * @param list<array{string, int, int, int}> $kills
     */
    private function kills(Node $node, array &$kills): void
    {
        foreach ($node->getSubNodeNames() as $subNodeName) {
            $children = is_array($node->{$subNodeName}) ? $node->{$subNodeName} : [$node->{$subNodeName}];
            $statements = array_values(array_filter($children, static fn(mixed $child): bool => $child instanceof Stmt));

            foreach ($statements as $statement) {
                if ($statement instanceof Expression && $statement->expr instanceof Assign && $statement->expr->var instanceof Variable && is_string($statement->expr->var->name)) {
                    $kills[] = [$statement->expr->var->name, $statement->expr->getStartFilePos(), $statement->getEndFilePos(), $statements[count($statements) - 1]->getEndFilePos()];
                }
            }

            foreach ($children as $child) {
                if ($child instanceof Node) {
                    $this->kills($child, $kills);
                }
            }
        }
    }

    /**
     * @param array<Node>        $nodes
     * @param class-string<Node> $type
     */
    private function contains(array $nodes, string $type): bool
    {
        foreach ($nodes as $node) {
            if ($node instanceof $type) {
                return true;
            }

            foreach ($node->getSubNodeNames() as $subNodeName) {
                $children = $node->{$subNodeName};

                if ($this->contains(array_filter(is_array($children) ? $children : [$children], static fn(mixed $child): bool => $child instanceof Node), $type)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * The offset a flow into a local is made at, or null inside a closure,
     * which may run after any later statement.
     */
    private function definedAt(Node $node, Scope $scope): ?int
    {
        return $scope->isInAnonymousFunction() ? null : $node->getStartFilePos();
    }

    /**
     * @param list<array{string, Term}> $flows
     *
     * @return Facts|null
     */
    private function flows(string $scope, array $flows, ?int $at): ?array
    {
        return $this->placedFlows($scope, $this->placed($flows, $at));
    }

    /**
     * @param list<array{string, Term}> $flows
     *
     * @return list<array{string, Term, int|null}>
     */
    private function placed(array $flows, ?int $at): array
    {
        return array_map(static fn(array $flow): array => [$flow[0], $flow[1], $at], $flows);
    }

    /**
     * @param list<array{string, Term, int|null}> $flows
     *
     * @return Facts|null
     */
    private function placedFlows(string $scope, array $flows): ?array
    {
        $flows = array_values(array_filter($flows, static fn(array $flow): bool => $flow[1] !== []));

        return $flows === [] ? null : ['scope' => $scope, 'flows' => $flows];
    }

    /**
     * Where an assignment's value lands. A local variable is four slots, one
     * per half of what it holds (see `halves()`), so a map hoisted into a
     * variable, or copied from one, keeps its keys apart from its values; a
     * value whose halves are all one term is one flow into every slot.
     * `$map[$key] = …` lands in the array holding it, key included; a
     * destructuring assignment lands the value half in every element; a
     * property lands in its heap slot, or — when the receiver's type declares
     * none — in the receiver itself.
     *
     * @param array{Term, Term, Term, Term} $halves the value assigned, by `halves()`
     *
     * @return list<array{string, Term}>
     */
    private function assignment(Expr $target, array $halves, Scope $scope): array
    {
        [$whole, , $values] = $halves;

        if ($target instanceof Variable) {
            if (!is_string($target->name)) {
                return [];
            }

            if ($halves === [$whole, $whole, $whole, $whole]) {
                return [['*|' . $target->name, $whole]];
            }

            return array_map(static fn(string $slot, array $term): array => [$slot . '|' . $target->name, $term], self::SLOTS, $halves);
        }

        if ($target instanceof ArrayDimFetch) {
            $index = $this->dimTerm($target, $scope);

            return $this->assignment($target->var, [[...$index, ...$whole], $this->entryKey($target->dim, $whole, $scope), $whole, $index], $scope);
        }

        if ($target instanceof List_ || $target instanceof Array_) {
            $flows = [];

            foreach ($target->items as $item) {
                if ($item !== null) {
                    $flows = [...$flows, ...$this->assignment($item->value, [$values, $values, $values, $values], $scope)];
                }
            }

            return $flows;
        }

        if ($target instanceof PropertyFetch || $target instanceof StaticPropertyFetch) {
            $slots = $this->heapKeys($target, $scope);

            if ($slots === [] && $target instanceof PropertyFetch && $target->name instanceof Identifier) {
                return $this->assignment($target->var, [$whole, $whole, $whole, $whole], $scope);
            }

            return array_map(static fn(string $slot): array => [$slot, $whole], $slots);
        }

        return [];
    }

    /**
     * An expression by the halves a local holding it keeps apart: its whole
     * value; the keys a cache sink reads out of it as a map (`entryKey()`);
     * its values; and its own keys, which a foreach hands its key variable.
     * An array literal splits into them, a spread entry by the halves of what
     * it spreads; a ternary, `??` or array union by the halves of the operands
     * it may yield; a local variable names its own four slots; anything else
     * is its whole term in every half.
     *
     * @return array{Term, Term, Term, Term}
     */
    private function halves(Expr $expr, Scope $scope): array
    {
        if ($expr instanceof Variable) {
            $atom = is_string($expr->name) && $expr->name !== 'this' ? [$expr->name, $expr->getStartFilePos()] : null;

            return array_map(static fn(string $slot): array => $atom === null ? [] : [[$slot, ...$atom]], self::SLOTS);
        }

        $whole = $this->term($expr, $scope);
        $parts = match (true) {
            $expr instanceof Array_ => $expr->items,
            $expr instanceof Ternary => [$expr->if ?? $expr->cond, $expr->else],
            $expr instanceof Coalesce, $expr instanceof Plus => [$expr->left, $expr->right],
            default => null,
        };

        if ($parts === null) {
            return [$whole, $whole, $whole, $whole];
        }

        $halves = [$whole, [], [], []];

        foreach ($parts as $item) {
            if ($item instanceof Expr || $item->unpack) {
                [, $keys, $values, $index] = $this->halves($item instanceof Expr ? $item : $item->value, $scope);
            } else {
                $values = $this->term($item->value, $scope);
                $keys = $this->entryKey($item->key, $values, $scope);
                $index = $item->key instanceof Expr ? $this->term($item->key, $scope) : [];
            }

            $halves = [$whole, [...$halves[1], ...$keys], [...$halves[2], ...$values], [...$halves[3], ...$index]];
        }

        return $halves;
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
            $callees[$callee] = $this->argumentsByParameter($method, $this->spread($arguments, $nodeScope), $nodeScope);
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
                $receiving = array_filter($parameters, static fn(ParameterReflection $parameter): bool => $parameter->getName() === $name) ?: $overflow;
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
     * The arguments as PHP binds them: a spread array literal whose entries
     * has known keys becomes one argument per entry — positional for an
     * integer key, named for any other string; any other spread stays whole.
     *
     * @param array<Arg> $arguments
     *
     * @return array<Arg>
     */
    private function spread(array $arguments, Scope $scope): array
    {
        $spread = [];

        foreach ($arguments as $argument) {
            $entries = $argument->unpack && $argument->value instanceof Array_ ? $this->entries($argument->value, $scope) : null;
            $spread = [...$spread, ...$entries ?? [$argument]];
        }

        return $spread;
    }

    /**
     * @return list<Arg>|null null when an entry spreads again, or its key is not one known integer or string
     */
    private function entries(Array_ $array, Scope $scope): ?array
    {
        $entries = [];

        foreach ($array->items as $item) {
            $keys = $item->key instanceof Expr ? $scope->getType($item->key)->getConstantScalarValues() : [0];

            if ($item->unpack || count($keys) !== 1 || (!is_int($keys[0]) && !is_string($keys[0]))) {
                return null;
            }

            // PHP stores a decimal integer string as an integer key, which spreads positionally.
            $entries[] = new Arg($item->value, name: is_string($keys[0]) && (string) (int) $keys[0] !== $keys[0] ? new Identifier($keys[0]) : null);
        }

        return $entries;
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
        $key = $reads === 'all' ? null : $this->boundArgument($this->spread($arguments, $scope), $parameter);

        if ($key === null) {
            return $this->union(array_map(static fn(Arg $argument): Expr => $argument->value, $arguments), $scope);
        }

        return match ($reads) {
            'map' => $this->halves($key, $scope)[1],
            'values' => $this->halves($key, $scope)[2],
            default => $this->term($key, $scope),
        };
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
     * The keys Laravel reads out of one array entry: a string key names its
     * entry; any other entry — no key, or a key PHP may store as an integer
     * (it turns `'7'` into `7`) — may be keyed by its value, as `many()` does.
     *
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
            return is_string($expr->name) && $expr->name !== 'this' ? [['v', $expr->name, $expr->getStartFilePos()]] : [];
        }

        if ($expr instanceof Closure || $expr instanceof ArrowFunction) {
            return [];
        }

        if ($expr instanceof PropertyFetch || $expr instanceof NullsafePropertyFetch) {
            $attribute = $expr->name instanceof Identifier ? $expr->name->toString() : $this->constantName($expr->name, $scope);

            if ($attribute !== null && $this->isModel($expr->var, $scope)) {
                return $this->sources($expr->var, $attribute, $expr, $scope);
            }

            return [
                ...($attribute === null ? [] : $this->sources($expr->var, $attribute, $expr, $scope)),
                ...$this->heapAtoms($this->heapKeys($expr, $scope)),
                ...$this->term($expr->var, $scope),
            ];
        }

        if ($expr instanceof StaticPropertyFetch) {
            return $this->heapAtoms($this->heapKeys($expr, $scope));
        }

        if ($expr instanceof ArrayDimFetch && $expr->dim instanceof Expr) {
            $attributes = $scope->getType($expr->dim)->getConstantStrings();
            $sources = count($attributes) === 1 ? $this->sources($expr->var, $attributes[0]->getValue(), $expr, $scope) : [];

            if ($this->isModel($expr->var, $scope)) {
                return $sources;
            }

            return [...$sources, ...$this->union($this->childExpressions($expr), $scope)];
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

        $arguments = $expr->isFirstClassCallable() ? [] : $expr->getArgs();
        $attributes = $expr->name instanceof Identifier && in_array($expr->name->toLowerString(), self::ATTRIBUTE_READERS, true) && $arguments !== []
            ? $scope->getType($arguments[0]->value)->getConstantStrings()
            : [];
        $sources = count($attributes) === 1 ? $this->sources($expr->var, $attributes[0]->getValue(), $expr, $scope) : [];

        return $this->isModel($expr->var, $scope) ? [...$term, ...$sources] : [...$term, ...$sources, ...$this->term($expr->var, $scope)];
    }

    /**
     * The attribute a dynamic name reads when its type is one constant string
     * (`$vault->{'api_key'}`); null when it is only known at runtime.
     */
    private function constantName(Expr $name, Scope $scope): ?string
    {
        $names = $scope->getType($name)->getConstantStrings();

        return count($names) === 1 ? $names[0]->getValue() : null;
    }

    /**
     * @return Term one candidate source per model the receiver may be, whatever else it may be too
     */
    private function sources(Expr $receiver, string $attribute, Expr $read, Scope $scope): array
    {
        $sources = [];

        foreach (TypeCombinator::removeNull($scope->getType($receiver))->getObjectClassNames() as $class) {
            if (new ObjectType(Model::class)->isSuperTypeOf(new ObjectType($class))->yes()) {
                $sources[] = ['s', sprintf('%s|%s|%s:%d', $class, $attribute, basename($scope->getFile()), $read->getStartLine())];
            }
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
