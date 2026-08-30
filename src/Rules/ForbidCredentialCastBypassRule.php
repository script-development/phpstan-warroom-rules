<?php

declare(strict_types = 1);

namespace ScriptDevelopment\PhpstanWarroomRules\Rules;

use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Database\Query\Builder as QueryBuilder;
use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Identifier;
use PhpParser\Node\Scalar\String_;
use PhpParser\Node\Stmt\Class_;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\Stmt\Property;
use PhpParser\Node\Stmt\Return_;
use PHPStan\Analyser\Scope;
use PHPStan\Parser\Parser;
use PHPStan\Parser\ParserErrorsException;
use PHPStan\Reflection\ClassReflection;
use PHPStan\Reflection\ReflectionProvider;
use PHPStan\Rules\IdentifierRuleError;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;
use PHPStan\Type\Constant\ConstantArrayType;
use PHPStan\Type\ObjectType;
use PHPStan\Type\Type;
use PHPStan\Type\TypeCombinator;

use function array_key_exists;
use function array_keys;
use function array_reverse;
use function is_array;
use function sprintf;
use function str_starts_with;

/**
 * Forbids naming a `hashed`- or `encrypted`-cast Eloquent attribute as a key in
 * a QUERY-BUILDER write payload — `Model::query()->…->update([...])`,
 * `->insert(...)`, `->upsert(...)`, a relation-derived builder write, or a
 * `DB::table('…')` write on a table mapped to a model.
 *
 * Doctrine source: war-room §Architectural Principles #1 (Explicit over
 * implicit) + #10 (credential handling / rotation-invariance); ISO 27001 A.5.33
 * and AVG on the compliance territories.
 *
 * **Why this is a security rule, not a style rule.** Attribute casts fire on
 * the MODEL path only — `setAttribute()` runs the `hashed` / `encrypted` cast
 * when you assign `$model->password = $plain` and `save()`. A query-builder
 * write skips the model entirely: `Builder::update()` delegates to
 * `toBase()->update()`, which ships the array straight to SQL. The value lands
 * in the column verbatim — no hash, no encryption, no exception, and a green
 * test suite, because a test that reads the column back gets exactly what it
 * wrote. The failure is silent at every layer and is only ever discovered by
 * reading the database. Seed: lokalekeuze PR #65, where `ReissueVoucherAction`
 * wrote through the model by CHOICE while `BlockVoucherAction`'s builder idiom
 * sat one file away — nothing but author preference separated the safe site
 * from the unsafe one.
 *
 * Detection has three independent halves; all three must resolve or the rule
 * stays SILENT (a credential-flavoured false positive spends the gate's
 * authority faster than almost any other kind — ADR-0021 posture):
 *
 *   1. **Write verb + payload.** The call is one of `update`, `insert`,
 *      `insertOrIgnore`, `insertGetId`, `upsert`, `updateOrInsert`, and the
 *      payload argument resolves to a CONSTANT array type, so its keys are
 *      statically known. A payload built dynamically (`$data` of unknown
 *      shape, computed keys) is not a constant array type and is silent.
 *      Because the check is TYPE-based rather than AST-literal, a payload
 *      hoisted into a variable (`$p = ['password' => …]; $q->update($p);`) is
 *      still caught. A list-of-rows payload (`insert([[…], […]])`, `upsert`)
 *      is recognised by shape — all-integer keys over constant-array values —
 *      and each row's keys are collected.
 *
 *      Deliberately ABSENT from the verb list: `create`, `updateOrCreate`,
 *      `firstOrCreate`, `createOrFirst`. Those are Eloquent-builder methods
 *      that instantiate a model and `save()` it, so casts DO fire — flagging
 *      them would criminalize the remediation.
 *
 *   2. **Receiver is a builder, never a model.** The receiver type must be a
 *      subtype of `Illuminate\Database\Eloquent\Builder`,
 *      `Illuminate\Database\Query\Builder`, or
 *      `Illuminate\Database\Eloquent\Relations\Relation`. A `Model` receiver is
 *      structurally excluded, which is what keeps the whole model path silent:
 *      `$model->update([...])` routes through `fill()` → `setAttribute()` and
 *      casts fire, so it is correct and must not fire here.
 *
 *   3. **Model resolution.** From an Eloquent `Builder<TModel>` or a
 *      `Relation<TRelatedModel, …>` the model comes out of the GENERIC
 *      argument — the first type argument that is a `Model` subtype. For a
 *      relation the first template parameter is `TRelatedModel`, which is the
 *      model whose table the write targets, so the same "first Model-subtype
 *      argument" reading is correct for both. A bare
 *      `Illuminate\Database\Query\Builder` (what `DB::table('x')` yields)
 *      carries NO model in its type, so it is resolved by walking the fluent
 *      chain back to the `table('…')` call with a string-literal argument and
 *      looking that table name up in the configured
 *      `credentialCastTableModels` map. That map is EMPTY by default, so
 *      `DB::table()` writes are silent until a consumer opts in — guessing a
 *      model from a table name by inflection would be exactly the
 *      false-positive source this rule cannot afford.
 *
 * **Reading the casts.** The model's cast map is read from SOURCE, because
 * neither shape is reachable through reflection alone: the modern
 * `protected function casts(): array` form needs a method body, and invoking it
 * would mean instantiating an Eloquent model inside the analyser. The rule
 * injects PHPStan's own analysis parser (`@defaultAnalysisParser` — cached, so
 * a model file is parsed once per run), parses the file
 * `ClassReflection::getFileName()` names, locates the class by resolved
 * `namespacedName`, and collects `'column' => 'cast'` string pairs from BOTH
 * the `casts()` method's `return [...]` statements and a `$casts` property
 * default. Parents are walked and merged with the child winning, so a cast
 * declared on an abstract base still counts at the leaf. A model whose file
 * cannot be read, parsed, or located is treated as having no casts — silent.
 *
 * A cast counts as credential-bearing when its value is exactly `hashed`,
 * exactly `encrypted`, or begins with `encrypted:` (`encrypted:array`,
 * `encrypted:collection`, `encrypted:object`).
 *
 * Suppression: standard PHPStan inline-ignore mechanism on the rule's
 * identifier `forbidCredentialCastBypass.castBypassedByBuilderWrite`.
 *
 * Out of scope — each an accepted false NEGATIVE, never a false positive:
 *
 *   - **Class-based encrypted casts** — `AsEncryptedArrayObject::class`,
 *     `AsEncryptedCollection::class` and friends appear as `::class` constant
 *     fetches rather than the string values this rule matches. They carry the
 *     same bypass risk; a consumer needing them covered restates the column in
 *     string form or relies on the per-territory arch test.
 *   - **Dynamic payloads and dynamic keys** — not a constant array type, so
 *     the keys are not statically known.
 *   - **`upsert()`'s third argument** (the update-column list) — its column
 *     names are VALUES, not keys, and every column named there must already
 *     appear in the row payload this rule does read.
 *   - **Static-magic builder entry** (`Model::where(...)->update([...])`
 *     without larastan) — plain PHPStan cannot type `Model::__callStatic`, so
 *     the receiver resolves to an error type and the rule declines. Consumers
 *     running larastan get `Builder<TModel>` there and the rule fires normally;
 *     `Model::query()->…` resolves on plain PHPStan either way.
 *   - **Raw SQL** (`DB::update('update users set …')`) — no payload array.
 *
 * @implements Rule<MethodCall>
 */
final class ForbidCredentialCastBypassRule implements Rule
{
    private const string IDENTIFIER = 'forbidCredentialCastBypass.castBypassedByBuilderWrite';

    /**
     * Builder write verbs that ship their payload to SQL without routing
     * through `Model::setAttribute()`, mapped to the argument positions
     * carrying a `column => value` payload.
     *
     * `create` / `updateOrCreate` / `firstOrCreate` / `createOrFirst` are
     * deliberately absent — they build and `save()` a model, so casts fire.
     *
     * @var array<string, list<int>>
     */
    private const array WRITE_METHODS = [
        'update' => [0],
        'insert' => [0],
        'insertOrIgnore' => [0],
        'insertGetId' => [0],
        'upsert' => [0],
        'updateOrInsert' => [0, 1],
    ];

    /** The fluent-chain method whose string argument names the table. */
    private const string TABLE_SETTING_METHOD = 'table';

    /**
     * Cast values that mean "the model layer transforms this value on write".
     * `encrypted:array` / `encrypted:collection` / `encrypted:object` are
     * matched by the prefix entry.
     *
     * @var list<string>
     */
    private const array CREDENTIAL_CASTS = ['hashed', 'encrypted'];

    /**
     * Cast maps already read this run, keyed by model FQCN. A model is parsed
     * once even when a hundred call sites write to it.
     *
     * @var array<string, array<string, string>>
     */
    private array $castCache = [];

    /**
     * @param array<string, string> $credentialCastTableModels map of raw table
     *                                                         name to model
     *                                                         FQCN, used ONLY
     *                                                         to resolve
     *                                                         `DB::table('…')`
     *                                                         chains, which
     *                                                         carry no model in
     *                                                         their type. Empty
     *                                                         by default — an
     *                                                         unmapped table is
     *                                                         silent, never
     *                                                         guessed.
     */
    public function __construct(
        private ReflectionProvider $reflectionProvider,
        private Parser $parser,
        private array $credentialCastTableModels = [],
    ) {}

    public function getNodeType(): string
    {
        return MethodCall::class;
    }

    public function processNode(Node $node, Scope $scope): array
    {
        if (!$node->name instanceof Identifier) {
            return [];
        }

        $method = $node->name->toString();

        if (!array_key_exists($method, self::WRITE_METHODS)) {
            return [];
        }

        $modelFqcn = $this->resolveModel($node, $scope);

        if ($modelFqcn === null) {
            return [];
        }

        $casts = $this->credentialCastsFor($modelFqcn);

        if ($casts === []) {
            return [];
        }

        $errors = [];

        foreach ($this->payloadColumns($node, $scope, self::WRITE_METHODS[$method]) as $column) {
            if (!array_key_exists($column, $casts)) {
                continue;
            }

            $errors[] = $this->buildError($node, $modelFqcn, $column, $casts[$column], $method);
        }

        return $errors;
    }

    /**
     * Resolve the model whose table this write targets, or null when it cannot
     * be established statically. A `Model` receiver returns null on purpose —
     * the model path fires casts and is the remediation, not the violation.
     */
    private function resolveModel(MethodCall $node, Scope $scope): ?string
    {
        $receiverType = TypeCombinator::removeNull($scope->getType($node->var));

        if ((new ObjectType(Model::class))->isSuperTypeOf($receiverType)->yes()) {
            return null;
        }

        $isEloquentBuilder = (new ObjectType(EloquentBuilder::class))->isSuperTypeOf($receiverType)->yes();
        $isRelation = (new ObjectType(Relation::class))->isSuperTypeOf($receiverType)->yes();

        if ($isEloquentBuilder || $isRelation) {
            $fromGenerics = $this->modelFromGenerics($receiverType);

            if ($fromGenerics !== null) {
                return $fromGenerics;
            }
        }

        if (!(new ObjectType(QueryBuilder::class))->isSuperTypeOf($receiverType)->yes()) {
            return null;
        }

        return $this->modelFromChainTable($node->var);
    }

    /**
     * Pull the model out of a `Builder<TModel>` / `Relation<TRelatedModel, …>`
     * generic argument list — the first argument that is a Model subtype. For a
     * relation `TRelatedModel` comes first, and it is the model whose table the
     * write targets, so one reading serves both shapes.
     */
    private function modelFromGenerics(Type $receiverType): ?string
    {
        $modelType = new ObjectType(Model::class);

        foreach ($receiverType->getObjectClassReflections() as $classReflection) {
            // The ACTIVE template map holds the arguments this particular
            // instance was parameterized with, in template-declaration order —
            // `TModel` for a Builder, `TRelatedModel` first for a Relation.
            foreach ($classReflection->getActiveTemplateTypeMap()->getTypes() as $typeArgument) {
                if (!$modelType->isSuperTypeOf($typeArgument)->yes()) {
                    continue;
                }

                $referenced = $typeArgument->getReferencedClasses();

                if ($referenced !== []) {
                    return $referenced[0];
                }
            }
        }

        return null;
    }

    /**
     * Walk the fluent chain back to the nearest `table('…')` call with a
     * string-literal argument and look the table name up in the configured
     * map. An unmapped table, a non-literal argument, or no `table()` call at
     * all all yield null — the rule declines rather than guessing a model from
     * a table name.
     */
    private function modelFromChainTable(Expr $receiver): ?string
    {
        $current = $receiver;

        while ($current instanceof MethodCall || $current instanceof StaticCall) {
            if (
                $current->name instanceof Identifier
                && $current->name->toString() === self::TABLE_SETTING_METHOD
            ) {
                return $this->mappedModelForTableArg($current);
            }

            if ($current instanceof MethodCall) {
                $current = $current->var;

                continue;
            }

            // A StaticCall is the chain root — its own arguments were inspected
            // above, and there are no earlier hops to walk.
            return null;
        }

        return null;
    }

    private function mappedModelForTableArg(MethodCall|StaticCall $call): ?string
    {
        if (!isset($call->args[0]) || !$call->args[0] instanceof Node\Arg) {
            return null;
        }

        $value = $call->args[0]->value;

        if (!$value instanceof String_) {
            return null;
        }

        return $this->credentialCastTableModels[$value->value] ?? null;
    }

    /**
     * Distinct column names appearing as keys in the payload arguments at
     * `$argumentPositions`. Only constant array types are read; anything else
     * contributes nothing.
     *
     * Deduplicated on purpose: a multi-row `insert([['password' => …],
     * ['password' => …]])` names one offending column at one call site, and
     * reporting it once per row would put N identical errors on one line.
     *
     * @param list<int> $argumentPositions
     *
     * @return list<string>
     */
    private function payloadColumns(MethodCall $node, Scope $scope, array $argumentPositions): array
    {
        $seen = [];

        foreach ($argumentPositions as $position) {
            if (!isset($node->args[$position]) || !$node->args[$position] instanceof Node\Arg) {
                continue;
            }

            foreach ($this->constantArrayKeys($scope->getType($node->args[$position]->value)) as $column) {
                $seen[$column] = true;
            }
        }

        return array_keys($seen);
    }

    /**
     * String keys of a constant array type. A list of constant arrays (the
     * `insert([[…], […]])` / `upsert` row shape) is recognised by having no
     * string keys of its own while every value is itself a constant array, and
     * is descended into.
     *
     * @return list<string>
     */
    private function constantArrayKeys(Type $type): array
    {
        $columns = [];

        foreach ($type->getConstantArrays() as $constantArray) {
            foreach ($this->keysOfConstantArray($constantArray) as $column) {
                $columns[] = $column;
            }
        }

        return $columns;
    }

    /**
     * @return list<string>
     */
    private function keysOfConstantArray(ConstantArrayType $type): array
    {
        $columns = [];
        $nested = [];

        foreach ($type->getKeyTypes() as $index => $keyType) {
            $constantStrings = $keyType->getConstantStrings();

            if ($constantStrings !== []) {
                $columns[] = $constantStrings[0]->getValue();

                continue;
            }

            $valueType = $type->getValueTypes()[$index] ?? null;

            if ($valueType !== null) {
                foreach ($valueType->getConstantArrays() as $row) {
                    $nested[] = $row;
                }
            }
        }

        // A payload is EITHER a `column => value` map or a list of such maps.
        // Descending into rows only when the outer array contributed no column
        // names of its own keeps a map with one array-valued column (a `json`
        // cast written as an array) from being misread as a row list.
        if ($columns !== []) {
            return $columns;
        }

        foreach ($nested as $row) {
            foreach ($this->keysOfConstantArray($row) as $column) {
                $columns[] = $column;
            }
        }

        return $columns;
    }

    /**
     * The model's credential-bearing casts as `column => cast`, merged across
     * the ancestry with the child winning. Memoized per FQCN.
     *
     * @return array<string, string>
     */
    private function credentialCastsFor(string $modelFqcn): array
    {
        if (array_key_exists($modelFqcn, $this->castCache)) {
            return $this->castCache[$modelFqcn];
        }

        $this->castCache[$modelFqcn] = [];

        if (!$this->reflectionProvider->hasClass($modelFqcn)) {
            return [];
        }

        $classReflection = $this->reflectionProvider->getClass($modelFqcn);

        $casts = [];

        // Ancestors first so a cast redeclared on the child overwrites the
        // inherited one, matching how Laravel merges the maps at runtime.
        foreach (array_reverse([$classReflection, ...$classReflection->getParents()]) as $ancestor) {
            foreach ($this->declaredCasts($ancestor) as $column => $cast) {
                $casts[$column] = $cast;
            }
        }

        $credentialCasts = [];

        foreach ($casts as $column => $cast) {
            if ($this->isCredentialCast($cast)) {
                $credentialCasts[$column] = $cast;
            }
        }

        $this->castCache[$modelFqcn] = $credentialCasts;

        return $credentialCasts;
    }

    private function isCredentialCast(string $cast): bool
    {
        foreach (self::CREDENTIAL_CASTS as $credentialCast) {
            if ($cast === $credentialCast || str_starts_with($cast, $credentialCast . ':')) {
                return true;
            }
        }

        return false;
    }

    /**
     * `'column' => 'cast'` pairs declared on ONE class — read from the source
     * file, since a `casts()` method body is not reachable through reflection
     * and invoking it would mean instantiating an Eloquent model inside the
     * analyser. Both declaration forms are read: the `casts()` method's
     * `return [...]` statements and a `$casts` property default.
     *
     * @return array<string, string>
     */
    private function declaredCasts(ClassReflection $classReflection): array
    {
        $file = $classReflection->getFileName();

        if ($file === null) {
            return [];
        }

        try {
            $stmts = $this->parser->parseFile($file);
        } catch (ParserErrorsException) {
            return [];
        }

        $classNode = $this->findClassNode($stmts, $classReflection->getName());

        if ($classNode === null) {
            return [];
        }

        $casts = [];

        foreach ($classNode->stmts as $stmt) {
            if ($stmt instanceof ClassMethod && $stmt->name->toString() === 'casts') {
                foreach ($this->returnedArrays($stmt) as $array) {
                    foreach ($this->stringPairs($array) as $column => $cast) {
                        $casts[$column] = $cast;
                    }
                }

                continue;
            }

            if (!$stmt instanceof Property) {
                continue;
            }

            foreach ($stmt->props as $prop) {
                if ($prop->name->toString() !== 'casts' || $prop->default === null) {
                    continue;
                }

                foreach ($this->stringPairs($prop->default) as $column => $cast) {
                    $casts[$column] = $cast;
                }
            }
        }

        return $casts;
    }

    /**
     * Locate the class declaration for `$fqcn` among parsed statements. The
     * injected parser resolves names, so `namespacedName` is populated and the
     * match is exact rather than by short name.
     *
     * @param array<Node> $nodes
     */
    private function findClassNode(array $nodes, string $fqcn): ?Class_
    {
        foreach ($nodes as $node) {
            if ($node instanceof Class_ && $node->namespacedName?->toString() === $fqcn) {
                return $node;
            }

            $found = $this->findClassNode($this->childNodes($node), $fqcn);

            if ($found !== null) {
                return $found;
            }
        }

        return null;
    }

    /**
     * A node's direct child NODES. Sub-node slots also hold strings, ints,
     * booleans and nulls (`Class_::$flags`, `Identifier::$name`, …), so the
     * slot values are filtered rather than assumed traversable.
     *
     * @return list<Node>
     */
    private function childNodes(Node $node): array
    {
        $children = [];

        foreach ($node->getSubNodeNames() as $subNodeName) {
            $subNode = $node->{$subNodeName};

            foreach (is_array($subNode) ? $subNode : [$subNode] as $candidate) {
                if ($candidate instanceof Node) {
                    $children[] = $candidate;
                }
            }
        }

        return $children;
    }

    /**
     * Every array literal returned from a method body, including returns nested
     * inside conditionals.
     *
     * @return list<Expr\Array_>
     */
    private function returnedArrays(ClassMethod $method): array
    {
        $arrays = [];

        $this->collectReturnedArrays($this->childNodes($method), $arrays);

        return $arrays;
    }

    /**
     * @param list<Node>        $nodes
     * @param list<Expr\Array_> $arrays
     */
    private function collectReturnedArrays(array $nodes, array &$arrays): void
    {
        foreach ($nodes as $node) {
            if ($node instanceof Return_ && $node->expr instanceof Expr\Array_) {
                $arrays[] = $node->expr;

                continue;
            }

            // A nested closure or anonymous class carries its own returns,
            // which are not this method's cast map.
            if ($node instanceof Expr\Closure || $node instanceof Class_) {
                continue;
            }

            $this->collectReturnedArrays($this->childNodes($node), $arrays);
        }
    }

    /**
     * `'key' => 'value'` pairs of an array literal. Non-string keys and
     * non-string values (a `::class` constant fetch, a computed expression) are
     * skipped — see the class docblock's out-of-scope list.
     *
     * @return array<string, string>
     */
    private function stringPairs(Expr $expr): array
    {
        if (!$expr instanceof Expr\Array_) {
            return [];
        }

        $pairs = [];

        foreach ($expr->items as $item) {
            if ($item->key instanceof String_ && $item->value instanceof String_) {
                $pairs[$item->key->value] = $item->value->value;
            }
        }

        return $pairs;
    }

    private function buildError(
        MethodCall $node,
        string $modelFqcn,
        string $column,
        string $cast,
        string $method,
    ): IdentifierRuleError {
        $message = sprintf(
            "Attribute '%s' on %s carries the '%s' cast, but %s() is a query-builder write that bypasses the cast and stores the raw value. Write it through the model path instead (assign the attribute and save the model).",
            $column,
            $modelFqcn,
            $cast,
            $method,
        );

        return RuleErrorBuilder::message($message)
            ->identifier(self::IDENTIFIER)
            ->line($node->getStartLine())
            ->build();
    }
}
