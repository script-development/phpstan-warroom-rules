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
use PhpParser\Node\FunctionLike;
use PhpParser\Node\Identifier;
use PhpParser\Node\Scalar\String_;
use PhpParser\Node\Stmt\Class_;
use PhpParser\Node\Stmt\ClassLike;
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
use function implode;
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
 * default.
 *
 * Both the ancestry AND the trait use-chain are walked — Laravel models compose
 * cast maps from traits routinely, and a credential cast declared in a trait
 * would otherwise silently exempt every model using it. `getTraits(true)`
 * flattens traits-used-by-traits, so a cast two hops away still counts. The
 * merge reproduces PHP's own member resolution: per ancestor, oldest first,
 * traits then the class's own declarations — so a class-declared cast beats a
 * trait-imported one, a trait-imported cast beats an inherited one, and the leaf
 * beats everything.
 *
 * A cast map composed rather than returned literally is READ, not missed:
 * `return array_merge(parent::casts(), ['password' => 'hashed']);` and
 * `return [...parent::casts(), 'password' => 'hashed'];` both contribute their
 * literal, and the ancestor being merged in is walked separately, so the merged
 * map is complete. Array literals are collected from anywhere inside a returned
 * expression — but never from inside an already-collected array, so a
 * nested-array cast value stays a value rather than becoming a second cast map.
 *
 * Three failure modes are each reported under their OWN identifier, because
 * MISSING, FAILED and MISCONFIGURED must not arrive as the same (silent)
 * outcome, and each has a different remediation:
 *
 *   - **`…modelSourceUnreadable`** — a declaring source whose PHP cannot be
 *     located or parsed. Fix the source.
 *   - **`…castMapIncomplete`** — the source WAS read, but a `casts()` return or
 *     a `$casts` default carries no array literal at all (`return self::CASTS;`,
 *     `return $this->buildCasts();`). Restate the credential columns literally.
 *   - **`…configuredModelMissing`** — `credentialCastTableModels` maps a table
 *     to a class that does not exist. Fix the parameter. Reachable only from the
 *     config map: an FQCN taken from a resolved generic type always exists.
 *
 * All three are reported REGARDLESS of the payload, because with an incomplete
 * map the rule cannot claim the payload is clean. Treating any of them as
 * "declares no casts" would fail OPEN on exactly the models this rule exists to
 * guard, and would make MISSING indistinguishable from FAILED.
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
 *   - **A `DB::table('…')` builder hoisted into a variable** — `$q =
 *     DB::table('users'); $q->update([...]);`. The chain walk needs the
 *     `table('…')` literal, and the variable's TYPE is a bare
 *     `Illuminate\Database\Query\Builder` that carries no table name, so once
 *     the builder is behind a variable there is nothing left to resolve. Chain
 *     forms ARE covered, including `DB::connection('…')->table('…')` and any
 *     number of intermediate hops. This is a limitation of the query builder's
 *     type, not of the walk.
 *   - **Static-magic builder entry** (`Model::where(...)->update([...])`
 *     without larastan) — plain PHPStan cannot type `Model::__callStatic`, so
 *     the receiver resolves to an error type and the rule declines. Consumers
 *     running larastan get `Builder<TModel>` there and the rule fires normally;
 *     `Model::query()->…` resolves on plain PHPStan either way.
 *   - **Raw SQL** (`DB::update('update users set …')`) — no payload array.
 *   - **A composition mixing a literal with a dynamic contributor** —
 *     `return array_merge($this->dynamicCasts(), ['password' => 'hashed']);`.
 *     The literal IS read, so the map is not reported as incomplete, and
 *     whatever the dynamic half contributes stays invisible. Reporting here
 *     would mean flagging every model that composes at all, including the ones
 *     read in full; the literal half being covered is the honest limit.
 *
 * @implements Rule<MethodCall>
 */
final class ForbidCredentialCastBypassRule implements Rule
{
    private const string IDENTIFIER = 'forbidCredentialCastBypass.castBypassedByBuilderWrite';

    /**
     * Reported when a declaring source could not be read, so the cast map is
     * INCOMPLETE and the rule cannot vouch for the payload. A distinct
     * identifier because MISSING and FAILED must not arrive as the same
     * (silent) outcome — a consumer can suppress this one alone without
     * disarming the real check.
     */
    private const string UNREADABLE_IDENTIFIER = 'forbidCredentialCastBypass.modelSourceUnreadable';

    /**
     * Reported when a declaring source WAS read but one of its cast
     * declarations could not be interpreted — a `casts()` return or a `$casts`
     * default that contributes no array literal at all (`return self::CASTS;`,
     * `return $this->buildCasts();`). Distinct from UNREADABLE_IDENTIFIER
     * because the remediation is different: the file is fine, the declaration
     * shape is what this rule cannot read, and restating the credential columns
     * in literal form fixes it. Composition forms that DO carry a literal
     * (`array_merge(parent::casts(), [...])`, `[...parent::casts(), ...]`) are
     * read, not reported.
     */
    private const string INCOMPLETE_IDENTIFIER = 'forbidCredentialCastBypass.castMapIncomplete';

    /**
     * Reported when `credentialCastTableModels` maps a table to a class that
     * does not exist. A typo or a stale rename would otherwise be
     * indistinguishable from "this table is not mapped", which silently and
     * permanently disarms the rule for every write on that table — the same
     * fail-open shape UNREADABLE_IDENTIFIER exists to prevent, arriving through
     * the configuration instead of the source.
     */
    private const string CONFIG_IDENTIFIER = 'forbidCredentialCastBypass.configuredModelMissing';

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
     * Cast resolutions already computed this run, keyed by model FQCN. A model
     * is parsed once even when a hundred call sites write to it.
     *
     * @var array<string, array{casts: array<string, string>, unreadable: list<string>, incomplete: list<string>, missing: bool}>
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

        $resolution = $this->castResolutionFor($modelFqcn);

        $errors = [];

        // Reported REGARDLESS of the payload, and deliberately: when a
        // declaring source could not be read, this rule does not know which
        // columns carry a credential cast, so it cannot say the payload is
        // clean. Staying silent here would be a fail-open on the one rule whose
        // whole value is catching a silent plaintext write.
        if ($resolution['missing']) {
            $errors[] = $this->buildConfiguredModelMissingError($node, $modelFqcn);
        }

        if ($resolution['unreadable'] !== []) {
            $errors[] = $this->buildUnreadableSourceError($node, $modelFqcn, $resolution['unreadable']);
        }

        if ($resolution['incomplete'] !== []) {
            $errors[] = $this->buildIncompleteCastMapError($node, $modelFqcn, $resolution['incomplete']);
        }

        foreach ($this->payloadColumns($node, $scope, self::WRITE_METHODS[$method]) as $column) {
            if (!array_key_exists($column, $resolution['casts'])) {
                continue;
            }

            $errors[] = $this->buildError($node, $modelFqcn, $column, $resolution['casts'][$column], $method);
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
     * the ancestry AND the trait use-chain, plus the list of declaring sources
     * whose PHP could not be read. Memoized per FQCN.
     *
     * Merge order reproduces PHP's own member resolution: for each ancestor,
     * oldest first, apply that ancestor's traits and then its own declarations.
     * A class-declared cast therefore beats one imported from its traits, a
     * trait-imported cast beats an inherited one, and the leaf beats everything.
     *
     * The `unreadable` list is the reason this returns a shape rather than a
     * bare map: an ancestor whose source cannot be parsed yields the SAME empty
     * cast set as an ancestor that genuinely declares none, and silently
     * treating the two alike would make the rule fail OPEN on exactly the models
     * it exists to guard. See `buildUnreadableSourceError()`.
     *
     * @return array{casts: array<string, string>, unreadable: list<string>, incomplete: list<string>, missing: bool}
     */
    private function castResolutionFor(string $modelFqcn): array
    {
        if (array_key_exists($modelFqcn, $this->castCache)) {
            return $this->castCache[$modelFqcn];
        }

        $empty = ['casts' => [], 'unreadable' => [], 'incomplete' => [], 'missing' => false];
        $this->castCache[$modelFqcn] = $empty;

        // The class cannot be absent on the GENERIC path — that FQCN came out
        // of a resolved type — so this branch is reachable only from the
        // `credentialCastTableModels` map, where it means the configured class
        // does not exist. Returning the "no mapping" answer here would let a
        // typo disarm the rule permanently and silently.
        if (!$this->reflectionProvider->hasClass($modelFqcn)) {
            $resolution = ['casts' => [], 'unreadable' => [], 'incomplete' => [], 'missing' => true];
            $this->castCache[$modelFqcn] = $resolution;

            return $resolution;
        }

        $classReflection = $this->reflectionProvider->getClass($modelFqcn);

        $casts = [];
        $unreadable = [];
        $incomplete = [];

        foreach (array_reverse([$classReflection, ...$classReflection->getParents()]) as $ancestor) {
            // `getTraits(true)` flattens traits-used-by-traits, so a cast
            // declared two trait hops away still counts.
            foreach ($ancestor->getTraits(true) as $trait) {
                $this->mergeDeclaredCasts($trait, $casts, $unreadable, $incomplete);
            }

            $this->mergeDeclaredCasts($ancestor, $casts, $unreadable, $incomplete);
        }

        $credentialCasts = [];

        foreach ($casts as $column => $cast) {
            if ($this->isCredentialCast($cast)) {
                $credentialCasts[$column] = $cast;
            }
        }

        $resolution = [
            'casts' => $credentialCasts,
            'unreadable' => $unreadable,
            'incomplete' => $incomplete,
            'missing' => false,
        ];
        $this->castCache[$modelFqcn] = $resolution;

        return $resolution;
    }

    /**
     * Merge one declaring source's casts into `$casts`, recording the source's
     * FQCN in `$unreadable` when its PHP could not be located or parsed.
     *
     * @param array<string, string> $casts
     * @param list<string>          $unreadable
     * @param list<string>          $incomplete
     */
    private function mergeDeclaredCasts(
        ClassReflection $source,
        array &$casts,
        array &$unreadable,
        array &$incomplete,
    ): void {
        $declared = $this->declaredCasts($source);

        if ($declared === null) {
            $unreadable[] = $source->getName();

            return;
        }

        if (!$declared['complete']) {
            $incomplete[] = $source->getName();
        }

        foreach ($declared['casts'] as $column => $cast) {
            $casts[$column] = $cast;
        }
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
     * Returns NULL — never an empty map — when the source cannot be located or
     * parsed, so the caller can tell "declares no casts" from "we could not
     * look". An empty map with `complete: true` means the source was read and
     * declares none.
     *
     * `complete` is FALSE when the source was read but a cast declaration could
     * not be interpreted: a `casts()` return statement, or a `$casts` property
     * default, from which no array literal can be extracted at all. Composition
     * forms that DO carry a literal are read rather than reported — the array
     * inside `array_merge(parent::casts(), [...])` is collected, and a spread
     * (`[...parent::casts(), 'password' => 'hashed']`) is an array literal
     * whose spread item simply carries no string key. In both, the contributor
     * being merged in is an ancestor call, and the ancestry is walked
     * separately, so the merged map is complete. What cannot be read is a
     * declaration carrying no literal at all — `return self::CASTS;`,
     * `return $this->buildCasts();`, `protected $casts = self::CASTS;`.
     *
     * @return array{casts: array<string, string>, complete: bool}|null
     */
    private function declaredCasts(ClassReflection $classReflection): ?array
    {
        $file = $classReflection->getFileName();

        if ($file === null) {
            return null;
        }

        try {
            $stmts = $this->parser->parseFile($file);
        } catch (ParserErrorsException) {
            return null;
        }

        $classNode = $this->findClassNode($stmts, $classReflection->getName());

        if ($classNode === null) {
            return null;
        }

        $casts = [];
        $complete = true;

        foreach ($classNode->stmts as $stmt) {
            if ($stmt instanceof ClassMethod && $stmt->name->toString() === 'casts') {
                foreach ($this->returnedArrays($stmt, $complete) as $array) {
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

                if (!$prop->default instanceof Expr\Array_) {
                    $complete = false;

                    continue;
                }

                foreach ($this->stringPairs($prop->default) as $column => $cast) {
                    $casts[$column] = $cast;
                }
            }
        }

        return ['casts' => $casts, 'complete' => $complete];
    }

    /**
     * Locate the class-like declaration for `$fqcn` among parsed statements —
     * a class OR a trait, since Laravel models routinely compose their cast map
     * from traits. The injected parser resolves names, so `namespacedName` is
     * populated and the match is exact rather than by short name.
     *
     * @param array<Node> $nodes
     */
    private function findClassNode(array $nodes, string $fqcn): ?ClassLike
    {
        foreach ($nodes as $node) {
            if ($node instanceof ClassLike && $node->namespacedName?->toString() === $fqcn) {
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
     * Every array literal contributed by a `return` in a method body, including
     * returns nested inside conditionals and literals nested inside a
     * composition expression (`return array_merge(parent::casts(), [...]);`).
     *
     * `$complete` is set to FALSE when a return statement contributes no array
     * literal at all, so the caller can report an incomplete cast map instead
     * of silently reading it as "declares nothing".
     *
     * @return list<Expr\Array_>
     */
    private function returnedArrays(ClassMethod $method, bool &$complete): array
    {
        $arrays = [];

        $this->collectReturnedArrays($this->childNodes($method), $arrays, $complete);

        return $arrays;
    }

    /**
     * @param list<Node>        $nodes
     * @param list<Expr\Array_> $arrays
     */
    private function collectReturnedArrays(array $nodes, array &$arrays, bool &$complete): void
    {
        foreach ($nodes as $node) {
            if ($node instanceof Return_) {
                if ($node->expr === null) {
                    continue;
                }

                $returned = [];

                $this->collectArrayLiterals([$node->expr], $returned);

                if ($returned === []) {
                    $complete = false;

                    continue;
                }

                foreach ($returned as $array) {
                    $arrays[] = $array;
                }

                continue;
            }

            // A nested function-like (closure, arrow function, nested function
            // declaration) or anonymous class carries its own returns, which
            // are not this method's cast map.
            if ($node instanceof FunctionLike || $node instanceof Class_) {
                continue;
            }

            $this->collectReturnedArrays($this->childNodes($node), $arrays, $complete);
        }
    }

    /**
     * Array literals reachable inside one expression WITHOUT descending into a
     * collected array — so `['a' => ['b' => 'c']]` contributes the outer array
     * only, and `'b' => 'c'` never becomes a cast pair of its own. Composition
     * expressions DO contribute: `array_merge(parent::casts(), [...])` and a
     * ternary over two literals both yield the literals they carry.
     *
     * A literal inside a function-like (a closure or arrow function passed as an
     * argument, an anonymous class) is NOT collected — it is a callback's return
     * value, not this cast map, and harvesting it would be a false positive on a
     * column the model never casts.
     *
     * @param list<Node>        $nodes
     * @param list<Expr\Array_> $arrays
     */
    private function collectArrayLiterals(array $nodes, array &$arrays): void
    {
        foreach ($nodes as $node) {
            if ($node instanceof FunctionLike || $node instanceof Class_) {
                continue;
            }

            if ($node instanceof Expr\Array_) {
                $arrays[] = $node;

                continue;
            }

            $this->collectArrayLiterals($this->childNodes($node), $arrays);
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

    /**
     * @param list<string> $unreadable
     */
    private function buildUnreadableSourceError(
        MethodCall $node,
        string $modelFqcn,
        array $unreadable,
    ): IdentifierRuleError {
        $message = sprintf(
            'Cannot verify this write against %s: the PHP declaring %s could not be located or parsed, so the credential-cast map is incomplete and a hashed/encrypted column in this payload would go unreported. Fix the source, or suppress %s here if the write is known safe.',
            $modelFqcn,
            implode(', ', $unreadable),
            self::UNREADABLE_IDENTIFIER,
        );

        return RuleErrorBuilder::message($message)
            ->identifier(self::UNREADABLE_IDENTIFIER)
            ->line($node->getStartLine())
            ->build();
    }

    /**
     * @param list<string> $incomplete
     */
    private function buildIncompleteCastMapError(
        MethodCall $node,
        string $modelFqcn,
        array $incomplete,
    ): IdentifierRuleError {
        $message = sprintf(
            'Cannot verify this write against %s: %s declares casts in a form this rule cannot read (a casts() return or a $casts default carrying no array literal, such as a class constant or a helper call), so the credential-cast map is incomplete and a hashed/encrypted column in this payload would go unreported. Restate the credential columns as literal string pairs, or suppress %s here if the write is known safe.',
            $modelFqcn,
            implode(', ', $incomplete),
            self::INCOMPLETE_IDENTIFIER,
        );

        return RuleErrorBuilder::message($message)
            ->identifier(self::INCOMPLETE_IDENTIFIER)
            ->line($node->getStartLine())
            ->build();
    }

    private function buildConfiguredModelMissingError(MethodCall $node, string $modelFqcn): IdentifierRuleError
    {
        $message = sprintf(
            'Cannot verify this write: credentialCastTableModels maps this table to %s, which does not exist, so no credential-cast map could be read and a hashed/encrypted column in this payload would go unreported. Fix the FQCN in the parameter, or remove the mapping if the table is no longer covered.',
            $modelFqcn,
        );

        return RuleErrorBuilder::message($message)
            ->identifier(self::CONFIG_IDENTIFIER)
            ->line($node->getStartLine())
            ->build();
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
