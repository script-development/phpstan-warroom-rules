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
use function count;
use function implode;
use function in_array;
use function is_array;
use function sprintf;

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
 * Detection has three independent halves, and the rule stays SILENT unless all
 * three resolve — a credential-flavoured false positive spends the gate's
 * authority faster than almost any other kind (ADR-0021 posture). Read that as
 * the DISPOSITION it is, not as a guarantee: it says what the rule does when it
 * cannot resolve something, not that everything it does resolve is right. The
 * false positives this rule has actually shipped were all cases where it
 * resolved something confidently and wrongly, and each is now a fixture:
 *
 *   1. **Write verb + payload.** The call is one of the verbs in
 *      `WRITE_METHODS` — `update`, `insert`, `insertOrIgnore`, `insertGetId`,
 *      `upsert`, `updateOrInsert`, the Postgres-only `updateFrom` and
 *      `insertOrIgnoreReturning`, `incrementOrCreate`, and the increment family
 *      loud and quiet, whose extra payload reaches SQL through
 *      `update(array_merge($columns, $extra))` — and the payload argument
 *      resolves to a CONSTANT array type, so its keys are statically known.
 *      Each payload slot carries a parameter NAME as well as a position,
 *      because a named argument does not sit at its parameter's index once an
 *      earlier optional one is skipped (`increment('votes', extra: […])`); the
 *      names are checked against Laravel's own signatures by a test, since a
 *      rename upstream would otherwise disable the named lookup in silence. A payload built dynamically (`$data` of unknown
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
 *   2. **Receiver is a builder — and, for eight verbs, a model too.** The
 *      receiver type must be a subtype of
 *      `Illuminate\Database\Eloquent\Builder`,
 *      `Illuminate\Database\Query\Builder`, or
 *      `Illuminate\Database\Eloquent\Relations\Relation`. A `Model` receiver is
 *      excluded for every OTHER verb, which is what keeps the model path
 *      silent: `$model->update([...])` routes through `fill()` →
 *      `setAttribute()` and casts fire, so it is correct and must not fire.
 *
 *      **The exception is the increment family** (`MODEL_BYPASSING_METHODS`),
 *      and it is not a carve-out for convenience — `Model::increment()` is
 *      `protected`, but `Model::__call()` names all eight and forwards to them,
 *      and `Model::incrementOrDecrement()` casts the in-memory attribute
 *      through `forceFill($extra)` while handing the SAME `$extra` uncast to
 *      the query builder. The object ends up right and the row ends up
 *      plaintext. So "the model path is safe" is true per VERB, never
 *      structurally, and this list is the whole of the exception.
 *
 *   3. **Model resolution.** From an Eloquent `Builder<TModel>` or a
 *      `Relation<TRelatedModel, …>` the model comes out of the GENERIC
 *      argument — the first type argument that is a `Model` subtype, per UNION
 *      BRANCH: `Builder<User>|Builder<AuditLog>` is two cast maps, and letting
 *      one branch speak for both is wrong in both directions. For a
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
 * `namespacedName`, and collects `'column' => 'cast'` string pairs.
 *
 * **What it does with them is PHP's own resolution, not a merge.** Laravel
 * builds the effective map once — `array_merge($this->casts, $this->casts())`
 * in `HasAttributes::initializeHasAttributes()` — and the two halves resolve
 * differently:
 *
 *   - `$casts` is a PROPERTY: exactly ONE declaration survives, the most
 *     derived, REPLACING an ancestor's default rather than merging with it, and
 *     a class-declared default replacing a trait-imported one.
 *   - `casts()` is a METHOD read by a SINGLE virtual dispatch: only the nearest
 *     body runs. An ancestor's or a trait's body contributes NOTHING unless the
 *     body that runs calls `parent::casts()` AND captures the result — a bare
 *     `parent::casts();` statement changes nothing at runtime, so it must not
 *     extend the walk either.
 *
 * The method half therefore beats the property half on a shared column,
 * whatever order the two appear in the file.
 *
 * **Which body runs is resolved by REFLECTION, not by searching the source.**
 * `getNativeReflection()->getMethod('casts')` is the declaration PHP would
 * dispatch, and its file and start line locate it exactly — through a trait, and
 * through a trait ADAPTATION. A first-match walk over the imported traits gets
 * `use A, B { B::casts insteadof A; }` wrong whenever the excluded trait is
 * listed first, and an `as` alias the same way. The PROPERTY half does walk the
 * declaration chain, and that is PHP's answer there: adaptations are method-only,
 * and two sources declaring `$casts` with different defaults is a fatal error
 * rather than an ambiguity.
 *
 * A body with SEVERAL returns has no single static answer, so every branch is
 * read and the union taken — a column some branch casts as a credential IS cast
 * on that path. Where branches disagree about the same column the CREDENTIAL
 * cast wins, because source order is not a fact about which branch runs.
 *
 * Why this is spelled out at this length: merging every declaration in the
 * ancestry and letting the leaf win reads plausible and is wrong on NINE of the
 * twenty-three shapes in `CastDispatchShapes.php` — eight inventing a credential
 * cast the model does not have, the ninth calling a readable declaration
 * unreadable. Resolving the method half by first match over the imported traits
 * instead is wrong on two OTHERS, which is the point of keeping shapes for both
 * mistakes: a table that only refutes the reading you have already abandoned
 * measures nothing. The test beside that fixture computes its expectation from
 * PHP itself rather than from anyone's reading of Laravel.
 *
 * A cast map composed rather than returned literally is READ, not missed:
 * `return array_merge(parent::casts(), ['password' => 'hashed']);` and
 * `return [...parent::casts(), 'password' => 'hashed'];` both contribute their
 * literal AND capture the parent's map, so the chain walk continues upward and
 * the merged map is complete. A bare `return parent::casts();` carries no
 * literal at all and needs none, for the same reason, and one assigned to a
 * variable first (`$c = parent::casts(); return array_merge($c, […]);`) counts
 * too — what does NOT count is a call whose result is discarded. Array literals are collected from anywhere inside a returned
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
 * Out of scope. Every entry is an accepted false NEGATIVE — a write this rule
 * knowingly stays silent on. Nothing may be parked here to excuse a false
 * POSITIVE: a rule that invents a credential cast blocks a correct write, and on
 * a security rule that spends the gate's authority faster than a missed catch.
 * Three shapes that once lived here as "documented limits" were false positives
 * and were fixed instead.
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
 *   - **A composition mixing a readable contributor with a dynamic one** —
 *     `return array_merge($this->dynamicCasts(), ['password' => 'hashed']);`, or
 *     `array_merge(parent::casts(), self::EXTRA_CASTS)`. The literal and the
 *     parent chain ARE read, so the map is not reported as incomplete, and
 *     whatever the dynamic half contributes stays invisible. Reporting here
 *     would mean flagging every model that composes at all, including the ones
 *     read in full; the readable half being covered is the honest limit.
 *   - **Casts added at RUNTIME through `mergeCasts()` / `withCasts()`** — no
 *     declaration exists to read, so a column cast only that way is invisible.
 *     Documented rather than diagnosed on measured grounds: across the war-room
 *     fleet `mergeCasts()` appears in application code exactly once, inside a
 *     copy-pasted `newInstance()` override propagating a map this rule already
 *     reads, and `withCasts()` once, on a non-credential column and query-time
 *     only. A diagnostic keyed on those calls would have no true positive to
 *     find today and one false positive to produce.
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
     * through `Model::setAttribute()`, mapped to the payload SLOTS carrying a
     * `column => value` array — each slot the parameter's NAME and its position,
     * because a named argument does not sit at its position once an earlier
     * optional parameter is skipped.
     *
     * `create` / `updateOrCreate` / `firstOrCreate` / `createOrFirst` are
     * deliberately absent — they build and `save()` a model, so casts fire.
     *
     * @var array<string, list<array{0: string, 1: int}>>
     */
    private const array WRITE_METHODS = [
        'update' => [['values', 0]],
        'insert' => [['values', 0]],
        'insertOrIgnore' => [['values', 0]],
        'insertGetId' => [['values', 0]],
        'upsert' => [['values', 0]],
        'updateOrInsert' => [['attributes', 0], ['values', 1]],
        // Postgres-only builder writes that take a payload like `update()` and
        // reach SQL by the same route. Absent from `Eloquent\Builder`, but its
        // `__call` forwards them, so a model query reaches them too.
        'updateFrom' => [['values', 0]],
        'insertOrIgnoreReturning' => [['values', 0]],
        // The increment family ships an EXTRA payload to SQL by the same route:
        // `Query\Builder::incrementEach()` is literally
        // `update(array_merge($columns, $extra))`, and `increment()` /
        // `decrement()` delegate to it. A credential column named in either
        // array lands raw in the column with no model in the path.
        'increment' => [['extra', 2]],
        'decrement' => [['extra', 2]],
        'incrementEach' => [['columns', 0], ['extra', 1]],
        'decrementEach' => [['columns', 0], ['extra', 1]],
        // `Eloquent\Builder::incrementOrCreate()` routes its `$attributes`
        // through `firstOrCreate()` — a model save, so casts fire there and it
        // is deliberately NOT read — then hands `$extra` to `Model::increment()`.
        'incrementOrCreate' => [['extra', 4]],
        // Model-only, re-exposed through `Model::__call`. Same payload slot as
        // their loud counterparts.
        'incrementQuietly' => [['extra', 2]],
        'decrementQuietly' => [['extra', 2]],
        'incrementEachQuietly' => [['columns', 0], ['extra', 1]],
        'decrementEachQuietly' => [['columns', 0], ['extra', 1]],
    ];

    /**
     * The verbs whose payload bypasses casts even on a MODEL receiver, so the
     * receiver type gate must NOT exclude them.
     *
     * `Model::increment()` is `protected`, but `Model::__call()` names all eight
     * explicitly and forwards to them, so `$model->increment(…)` is reachable
     * from anywhere. `Model::incrementOrDecrement()` then casts the in-memory
     * attribute through `forceFill($extra)` and passes the SAME `$extra`,
     * uncast, to the query builder — so the object is right and the row is
     * plaintext. Verified against `Illuminate\Database\Eloquent\Model`.
     *
     * @var list<string>
     */
    private const array MODEL_BYPASSING_METHODS = [
        'increment',
        'decrement',
        'incrementEach',
        'decrementEach',
        'incrementQuietly',
        'decrementQuietly',
        'incrementEachQuietly',
        'decrementEachQuietly',
    ];

    /** The fluent-chain method whose string argument names the table. */
    private const string TABLE_SETTING_METHOD = 'table';

    /**
     * The member name Eloquent reads casts from — the same string names the
     * `casts()` method and the `$casts` property, which is why both halves of
     * `declaredPropertyCasts()` and `dispatchedMethodCasts()` match on it.
     */
    private const string CASTS_MEMBER = 'casts';

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
     * Per-SOURCE `$casts` property declarations already read this run, keyed by
     * class-or-trait FQCN. The property walk visits every ancestor and its
     * traits, and one model is written to from many call sites.
     *
     * @var array<string, array{property: array<string, string>|null, complete: bool}|null>
     */
    private array $declarationCache = [];

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

        $modelFqcns = $this->resolveModels($node, $scope, $method);

        if ($modelFqcns === []) {
            return [];
        }

        $columns = $this->payloadColumns($node, $scope, self::WRITE_METHODS[$method]);
        $errors = [];

        foreach ($modelFqcns as $modelFqcn) {
            $resolution = $this->castResolutionFor($modelFqcn);

            // Reported REGARDLESS of the payload, and deliberately: when a
            // declaring source could not be read, this rule does not know which
            // columns carry a credential cast, so it cannot say the payload is
            // clean. Staying silent here would be a fail-open on the one rule
            // whose whole value is catching a silent plaintext write.
            if ($resolution['missing']) {
                $errors[] = $this->buildConfiguredModelMissingError($node, $modelFqcn);
            }

            if ($resolution['unreadable'] !== []) {
                $errors[] = $this->buildUnreadableSourceError($node, $modelFqcn, $resolution['unreadable']);
            }

            if ($resolution['incomplete'] !== []) {
                $errors[] = $this->buildIncompleteCastMapError($node, $modelFqcn, $resolution['incomplete']);
            }

            foreach ($columns as $column) {
                if (!array_key_exists($column, $resolution['casts'])) {
                    continue;
                }

                $errors[] = $this->buildError($node, $modelFqcn, $column, $resolution['casts'][$column], $method);
            }
        }

        return $errors;
    }

    /**
     * Every model whose table this write could target — normally one, more when
     * the receiver's type is a UNION of builders. Empty when no model can be
     * established statically.
     *
     * A `Model` receiver returns nothing on purpose: the model path fires casts
     * and is the remediation, not the violation.
     *
     * A union is checked branch by branch rather than collapsed to its first
     * member. `Builder<User>|Builder<AuditLog>` is two different cast maps, and
     * picking one to speak for both is wrong in both directions — it misses a
     * credential the other branch casts, and it reports one the branch in hand
     * does not. Two errors on one line for a genuinely ambiguous receiver is the
     * honest answer: each branch is a real write.
     *
     * @return list<string>
     */
    private function resolveModels(MethodCall $node, Scope $scope, string $method): array
    {
        $receiverType = TypeCombinator::removeNull($scope->getType($node->var));

        if ((new ObjectType(Model::class))->isSuperTypeOf($receiverType)->yes()) {
            // The model path fires casts for every verb EXCEPT these — see
            // MODEL_BYPASSING_METHODS. This is the one place the receiver type
            // gate is not sufficient on its own.
            if (!in_array($method, self::MODEL_BYPASSING_METHODS, true)) {
                return [];
            }

            $models = [];

            foreach ($receiverType->getObjectClassReflections() as $classReflection) {
                $models[$classReflection->getName()] = true;
            }

            return array_keys($models);
        }

        $isEloquentBuilder = (new ObjectType(EloquentBuilder::class))->isSuperTypeOf($receiverType)->yes();
        $isRelation = (new ObjectType(Relation::class))->isSuperTypeOf($receiverType)->yes();

        if ($isEloquentBuilder || $isRelation) {
            $fromGenerics = $this->modelsFromGenerics($receiverType);

            if ($fromGenerics !== []) {
                return $fromGenerics;
            }
        }

        if (!(new ObjectType(QueryBuilder::class))->isSuperTypeOf($receiverType)->yes()) {
            return [];
        }

        $fromTable = $this->modelFromChainTable($node->var);

        return $fromTable === null ? [] : [$fromTable];
    }

    /**
     * The models named by `Builder<TModel>` / `Relation<TRelatedModel, …>`
     * generic arguments — per union branch, the first argument that is a Model
     * subtype. For a relation `TRelatedModel` comes first, and it is the model
     * whose table the write targets, so one reading serves both shapes.
     *
     * @return list<string>
     */
    private function modelsFromGenerics(Type $receiverType): array
    {
        $modelType = new ObjectType(Model::class);
        $models = [];

        foreach ($receiverType->getObjectClassReflections() as $classReflection) {
            // The ACTIVE template map holds the arguments this particular
            // instance was parameterized with, in template-declaration order —
            // `TModel` for a Builder, `TRelatedModel` first for a Relation.
            foreach ($classReflection->getActiveTemplateTypeMap()->getTypes() as $typeArgument) {
                if (!$modelType->isSuperTypeOf($typeArgument)->yes()) {
                    continue;
                }

                $referenced = $typeArgument->getReferencedClasses();

                if ($referenced === []) {
                    continue;
                }

                $models[$referenced[0]] = true;

                // One model per branch: the remaining template arguments of a
                // Relation (TDeclaringModel, …) are not write targets.
                break;
            }
        }

        return array_keys($models);
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
     * Distinct column names appearing as keys in the payload arguments named by
     * `$payloadSlots`. Only constant array types are read; anything else
     * contributes nothing.
     *
     * Deduplicated on purpose: a multi-row `insert([['password' => …],
     * ['password' => …]])` names one offending column at one call site, and
     * reporting it once per row would put N identical errors on one line.
     *
     * @param list<array{0: string, 1: int}> $payloadSlots
     *
     * @return list<string>
     */
    private function payloadColumns(MethodCall $node, Scope $scope, array $payloadSlots): array
    {
        $seen = [];

        foreach ($payloadSlots as [$name, $position]) {
            $argument = $this->argumentAt($node, $name, $position);

            if ($argument === null) {
                continue;
            }

            foreach ($this->constantArrayKeys($scope->getType($argument->value)) as $column) {
                $seen[$column] = true;
            }
        }

        return array_keys($seen);
    }

    /**
     * One argument, addressed by NAME first and by position second.
     *
     * A named argument does not sit at its parameter's position:
     * `increment('votes', extra: [...])` puts the payload at index 1, so reading
     * index 2 finds nothing and the write passes silently. Since PHP 8.0 any
     * caller may name any argument, so a position-only reading is a
     * false-negative generator on every verb here — and on the increment family
     * especially, whose payload is the THIRD parameter and whose second
     * (`$amount`) has a default worth skipping.
     */
    private function argumentAt(MethodCall $node, string $name, int $position): ?Node\Arg
    {
        foreach ($node->args as $argument) {
            if (
                $argument instanceof Node\Arg
                && $argument->name instanceof Identifier
                && $argument->name->toString() === $name
            ) {
                return $argument;
            }
        }

        // PHP requires every positional argument before the first named one, so
        // positional slots are contiguous from zero and this index is only
        // meaningful when the argument sitting there is itself positional.
        // Checking the slot rather than refusing whenever ANY argument is named
        // keeps `upsert($values, uniqueBy: […])` covered.
        $argument = $node->args[$position] ?? null;

        return $argument instanceof Node\Arg && $argument->name === null ? $argument : null;
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
     * The model's credential-bearing casts as `column => cast`, resolved the way
     * PHP and Laravel actually resolve them, plus the declaring sources whose
     * PHP could not be read or interpreted. Memoized per FQCN.
     *
     * Laravel builds the effective map exactly once, in
     * `HasAttributes::initializeHasAttributes()`:
     *
     *     $this->casts = array_merge($this->casts, $this->casts());
     *
     * Two halves, two DIFFERENT PHP resolution rules, and reading either as a
     * merge across every declaration produces a FALSE POSITIVE on a column the
     * model does not cast:
     *
     *   - `$casts` is a PROPERTY. Exactly ONE declaration survives — the most
     *     derived — and a redeclaration REPLACES the ancestor's default rather
     *     than merging with it. A class-declared default likewise replaces a
     *     trait-imported one.
     *   - `casts()` is a METHOD, and reading it is a SINGLE virtual dispatch.
     *     Only the nearest declaration's body runs. An ancestor's or a trait's
     *     body contributes NOTHING unless the body that does run calls
     *     `parent::casts()` — the one construct that makes an ancestor's map
     *     part of the answer, and the only reason to walk upward at all.
     *
     * Because the merge puts `casts()` second, the method half beats the
     * property half for any column both declare — regardless of the order the
     * two appear in the source file.
     *
     * Measured against PHP's own answer over the twenty-three declaration shapes
     * in `CastDispatchShapes.php` (war-room enforcement #217): reading this as
     * "merge every declaration, leaf wins" is wrong on nine of them — eight
     * inventing a credential cast, one calling a readable declaration
     * unreadable — each masked in the obvious fixtures by a key collision.
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
        $chain = $this->declarationChain($classReflection);

        $unreadable = [];
        $incomplete = [];

        $casts = array_merge(
            $this->propertyCasts($chain, $unreadable, $incomplete),
            $this->dispatchedMethodCasts($classReflection, $unreadable, $incomplete),
        );

        $credentialCasts = [];

        foreach ($casts as $column => $cast) {
            if ($this->isCredentialCast($cast)) {
                $credentialCasts[$column] = $cast;
            }
        }

        $resolution = [
            'casts' => $credentialCasts,
            // Both halves walk the same sources, so a source that cannot be
            // read is reached twice and would otherwise be named twice in one
            // message.
            'unreadable' => array_values(array_unique($unreadable)),
            'incomplete' => array_values(array_unique($incomplete)),
            'missing' => false,
        ];
        $this->castCache[$modelFqcn] = $resolution;

        return $resolution;
    }

    /**
     * Every source that could carry a cast declaration, in PHP's own
     * member-resolution order: nearest ancestor first and, within one ancestor,
     * the class body before the traits it imports.
     *
     * The walk stops AT `Illuminate\Database\Eloquent\Model`. The framework's
     * own `$casts = []` and `casts(): array { return []; }` contribute nothing,
     * so parsing vendor source to discover that is wasted work — and it keeps
     * the rule from reporting the framework as an unreadable declaring source
     * on a consumer tree that ships no vendor PHP.
     *
     * @return list<array{class: ClassReflection, sources: list<ClassReflection>}>
     */
    private function declarationChain(ClassReflection $classReflection): array
    {
        $chain = [];

        foreach ([$classReflection, ...$classReflection->getParents()] as $ancestor) {
            if ($ancestor->getName() === Model::class) {
                break;
            }

            $chain[] = [
                'class' => $ancestor,
                'sources' => [$ancestor, ...$this->importedTraits($ancestor)],
            ];
        }

        return $chain;
    }

    /**
     * The traits ONE class-like imports, depth first, the importing trait before
     * the traits it imports itself — PHP's precedence, since a trait's own
     * member beats one it pulled in.
     *
     * Measured, and the reason this is hand-rolled rather than
     * `ClassReflection::getTraits(true)`: that helper walks the PARENT CHAIN as
     * well, so a model importing no traits at all reports twelve of them —
     * Laravel's own `HasAttributes` among them, which declares BOTH
     * `$casts = []` and `casts(): array`. Under a resolution that stops at the
     * first declaration it finds, inheriting the framework's empty declaration
     * into every subclass silently answers "this model casts nothing".
     *
     * @param array<string, true> $seen guards a diamond import, where two
     *                                  imported traits pull in a third
     *
     * @return list<ClassReflection>
     */
    private function importedTraits(ClassReflection $classReflection, array &$seen = []): array
    {
        $traits = [];

        foreach ($classReflection->getTraits() as $trait) {
            $name = $trait->getName();

            if (array_key_exists($name, $seen)) {
                continue;
            }

            $seen[$name] = true;
            $traits[] = $trait;

            foreach ($this->importedTraits($trait, $seen) as $nested) {
                $traits[] = $nested;
            }
        }

        return $traits;
    }

    /**
     * The ONE `$casts` property default that survives PHP's property
     * resolution: the most derived declaration, class body before trait,
     * replacing rather than merging whatever an ancestor declared.
     *
     * Two traits declaring `$casts` with different defaults, or a class
     * redeclaring a trait's with a different default, is a PHP FATAL
     * ("definition differs and is considered incompatible"), so an ambiguous
     * property cannot reach this walk from valid code.
     *
     * @param list<array{class: ClassReflection, sources: list<ClassReflection>}> $chain
     * @param list<string>                                                        $unreadable
     * @param list<string>                                                        $incomplete
     *
     * @return array<string, string>
     */
    private function propertyCasts(array $chain, array &$unreadable, array &$incomplete): array
    {
        foreach ($chain as $entry) {
            foreach ($entry['sources'] as $source) {
                $declared = $this->declaredPropertyCasts($source);

                if ($declared === null) {
                    $unreadable[] = $source->getName();

                    continue;
                }

                if ($declared['property'] === null) {
                    continue;
                }

                if (!$declared['complete']) {
                    $incomplete[] = $source->getName();
                }

                return $declared['property'];
            }
        }

        return [];
    }

    /**
     * The map a `$model->casts()` call would actually produce.
     *
     * The declaration that runs is resolved by REFLECTION, not by searching the
     * ancestry: `getNativeReflection()->getMethod('casts')` is the method PHP
     * would dispatch, and its `getFileName()` / `getStartLine()` locate that
     * body exactly — through a trait, and through a trait ADAPTATION. Verified:
     * on `use A, B { B::casts insteadof A; }` reflection points at B's body,
     * which a first-match walk over the imported traits gets wrong whenever the
     * excluded trait is listed first. An `as` alias has the same shape.
     *
     * The walk continues upward only when the body that runs uses
     * `parent::casts()` — and then from the parent of the class that DECLARES
     * that body, not of the class the write targeted, because `parent::` inside
     * an inherited body resolves against that body's own class.
     *
     * @param list<string> $unreadable
     * @param list<string> $incomplete
     *
     * @return array<string, string>
     */
    private function dispatchedMethodCasts(
        ClassReflection $classReflection,
        array &$unreadable,
        array &$incomplete,
    ): array {
        $maps = [];
        $current = $classReflection;
        $visited = [];

        // BOUNDED, not merely guarded. The walk only ever moves upward, so the
        // ancestry depth is its ceiling — and an unbounded loop here would hang
        // the consumer's analysis with no error at all rather than reporting
        // something wrong. Mutation testing makes the difference visible: turning
        // the visited-guard `break` below into `continue` spins forever, which is
        // a timeout in CI and a mystery in a consumer's pipeline.
        $remaining = count($classReflection->getParents()) + 1;

        while ($current !== null && $remaining-- > 0) {
            $native = $current->getNativeReflection();

            if (!$native->hasMethod(self::CASTS_MEMBER)) {
                break;
            }

            $method = $native->getMethod(self::CASTS_MEMBER);
            $declaringClass = $method->getDeclaringClass()->getName();

            // Laravel's own `casts(): array { return []; }`. Reaching it means
            // nothing in the consumer's hierarchy declares one.
            if ($declaringClass === Model::class) {
                break;
            }

            // A `parent::casts()` chain cannot revisit a class; guard anyway so
            // a pathological hierarchy cannot spin here.
            if (array_key_exists($declaringClass, $visited)) {
                break;
            }

            $visited[$declaringClass] = true;

            $file = $method->getFileName();
            $node = $file === false
                ? null
                : $this->castsMethodNodeAt($file, $method->getStartLine());

            if ($node === null) {
                $unreadable[] = $declaringClass;

                break;
            }

            $complete = true;
            $maps[] = $this->castsFromReturns($node, $complete);

            if (!$complete) {
                $incomplete[] = $declaringClass;
            }

            if (!$this->contributesParentCasts($node)) {
                break;
            }

            $current = $this->reflectionProvider->hasClass($declaringClass)
                ? $this->reflectionProvider->getClass($declaringClass)->getParentClass()
                : null;
        }

        $casts = [];

        // Nearest declaration wins, so merge oldest-first.
        foreach (array_reverse($maps) as $map) {
            $casts = array_merge($casts, $map);
        }

        return $casts;
    }

    /**
     * The `casts()` declaration whose body starts at `$line` in `$file`.
     *
     * Matched on the EXACT start line rather than by locating the class, because
     * the body that runs may live in a trait the class only imports — and after
     * an `insteadof` it is not even the first trait declaring it. Measured:
     * php-parser and PHP reflection agree on this line, docblock and attribute
     * included. No match means the source moved under us, which is a read
     * FAILURE and reported as one, never a silent empty map.
     */
    private function castsMethodNodeAt(string $file, false|int $line): ?ClassMethod
    {
        if ($line === false) {
            return null;
        }

        try {
            $stmts = $this->parser->parseFile($file);
        } catch (ParserErrorsException) {
            return null;
        }

        foreach ($this->castsMethodNodes($stmts) as $candidate) {
            if ($candidate->getStartLine() === $line) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * Every `casts()` method declaration among parsed statements, at any depth —
     * one file can hold several classes and traits declaring it.
     *
     * @param array<Node> $nodes
     *
     * @return list<ClassMethod>
     */
    private function castsMethodNodes(array $nodes): array
    {
        $found = [];

        foreach ($nodes as $node) {
            if ($node instanceof ClassMethod && $node->name->toString() === self::CASTS_MEMBER) {
                $found[] = $node;
            }

            foreach ($this->castsMethodNodes($this->childNodes($node)) as $nested) {
                $found[] = $nested;
            }
        }

        return $found;
    }

    /**
     * The `column => cast` pairs one `casts()` body contributes.
     *
     * A body with SEVERAL returns (`if (…) { return [...]; } return [...];`) has
     * no single static answer, so every branch is read and the union is taken.
     * That is a deliberate bias: a column some branch casts as a credential IS
     * cast on that path, and a builder write to it is unsafe there. Where two
     * branches disagree about the SAME column, the CREDENTIAL cast wins rather
     * than whichever appears last — source order is not a fact about which
     * branch runs.
     *
     * @return array<string, string>
     */
    private function castsFromReturns(ClassMethod $method, bool &$complete): array
    {
        $casts = [];

        foreach ($this->returnedArrays($method, $complete) as $array) {
            foreach ($this->stringPairs($array) as $column => $cast) {
                if (
                    array_key_exists($column, $casts)
                    && $this->isCredentialCast($casts[$column])
                    && !$this->isCredentialCast($cast)
                ) {
                    continue;
                }

                $casts[$column] = $cast;
            }
        }

        return $casts;
    }

    /**
     * Whether this body's returned value actually DEPENDS on `parent::casts()`.
     *
     * A call whose result is thrown away — `parent::casts();` as a statement of
     * its own — contributes nothing at runtime, so inheriting the parent's map
     * on the strength of it invents casts the child does not have. Anything that
     * CAPTURES the result counts: returned directly, composed into a literal or
     * an `array_merge`, or assigned to a variable first.
     */
    private function contributesParentCasts(ClassMethod $method): bool
    {
        foreach ($this->childNodes($method) as $node) {
            if ($this->capturesParentCastsCall($node)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param Node $node a node whose own context is NOT a discarded expression
     *                   statement
     */
    private function capturesParentCastsCall(Node $node): bool
    {
        if ($node instanceof FunctionLike || $node instanceof Class_) {
            return false;
        }

        // `parent::casts();` alone: the call is the whole statement, so its
        // result goes nowhere.
        if ($node instanceof Node\Stmt\Expression && $this->isParentCastsCall($node->expr)) {
            return false;
        }

        if ($this->isParentCastsCall($node)) {
            return true;
        }

        foreach ($this->childNodes($node) as $child) {
            if ($this->capturesParentCastsCall($child)) {
                return true;
            }
        }

        return false;
    }

    private function isParentCastsCall(Node $node): bool
    {
        return $node instanceof StaticCall
            && $node->class instanceof Node\Name
            && $node->class->toLowerString() === 'parent'
            && $node->name instanceof Identifier
            && $node->name->toString() === self::CASTS_MEMBER;
    }

    /**
     * Whether a cast value means "the model layer transforms this on write" —
     * exactly `hashed`, exactly `encrypted`, or an `encrypted:` variant
     * (`encrypted:array`, `encrypted:collection`, `encrypted:object`).
     */
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
     * The `$casts` PROPERTY default declared by ONE source, read from its PHP
     * because a property default is not what reflection hands back on an
     * unconstructed analyser-side class.
     *
     * Property-only by design. The `casts()` METHOD half is resolved by
     * reflection in `dispatchedMethodCasts()`, which handles trait adaptations
     * a source walk cannot see. A property has no such adaptation — `insteadof`
     * and `as` are method-only, and two sources declaring `$casts` with
     * different defaults is a PHP fatal, not an ambiguity — so first-match over
     * the declaration chain IS PHP's answer here.
     *
     * Returns NULL — never an empty shape — when the source cannot be located or
     * parsed, so the caller can tell "declares nothing" from "we could not
     * look". Within the shape, `property` is NULL when this source declares no
     * `$casts` default at all, and an ARRAY (possibly empty) when it does: the
     * difference is what stops the walk. `complete` is FALSE when a `$casts`
     * default IS declared but carries no array literal to read
     * (`protected $casts = self::CASTS;`).
     *
     * @return array{property: array<string, string>|null, complete: bool}|null
     */
    private function declaredPropertyCasts(ClassReflection $classReflection): ?array
    {
        $name = $classReflection->getName();

        if (array_key_exists($name, $this->declarationCache)) {
            return $this->declarationCache[$name];
        }

        $this->declarationCache[$name] = null;

        $file = $classReflection->getFileName();

        if ($file === null) {
            return null;
        }

        try {
            $stmts = $this->parser->parseFile($file);
        } catch (ParserErrorsException) {
            return null;
        }

        $classNode = $this->findClassNode($stmts, $name);

        if ($classNode === null) {
            return null;
        }

        $property = null;
        $complete = true;

        foreach ($classNode->stmts as $stmt) {
            if (!$stmt instanceof Property) {
                continue;
            }

            foreach ($stmt->props as $prop) {
                if ($prop->name->toString() !== self::CASTS_MEMBER || $prop->default === null) {
                    continue;
                }

                if (!$prop->default instanceof Expr\Array_) {
                    $property = [];
                    $complete = false;

                    continue;
                }

                $property = $this->stringPairs($prop->default);
            }
        }

        $declaration = ['property' => $property, 'complete' => $complete];
        $this->declarationCache[$name] = $declaration;

        return $declaration;
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
                    // `return parent::casts();` carries no literal of its own
                    // and needs none — the ancestor's declaration is a
                    // resolvable chain link that dispatchedMethodCasts() walks.
                    // Reporting it as uninterpretable would be a false positive
                    // on the idiomatic pass-through override.
                    if (!$this->isParentCastsCall($node->expr) && !$this->capturesParentCastsCall($node->expr)) {
                        $complete = false;
                    }

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
