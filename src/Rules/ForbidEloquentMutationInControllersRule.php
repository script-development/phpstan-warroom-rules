<?php

declare(strict_types = 1);

namespace ScriptDevelopment\PhpstanWarroomRules\Rules;

use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Eloquent\Model;
use PhpParser\Node;
use PhpParser\Node\Expr\CallLike;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\IdentifierRuleError;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;
use PHPStan\Type\ObjectType;
use PHPStan\Type\Type;

use function in_array;
use function mb_strrpos;
use function mb_substr;
use function sprintf;
use function str_starts_with;

/**
 * Forbids Eloquent persistence-API method calls on `Illuminate\Database\Eloquent\
 * Model` subclasses (or `Illuminate\Database\Eloquent\Builder` chains over them)
 * inside classes whose FQCN starts with `App\Http\Controllers`. Controllers must
 * delegate mutations to Actions — Actions are the chokepoint that owns the
 * transaction boundary and the audit-row write (ADR-0011 + ADR-0001 +
 * ADR-0029).
 *
 * Doctrine source: ADR-0011 (Action Class Architecture) + ADR-0019 (Explicit
 * Model Hydration).
 *
 * Read methods (`find`, `where`, `get`, `first`, `paginate`, `pluck`, `count`,
 * `exists`, `query`) are deliberately permitted — only mutations carry the
 * audit-bypass risk this rule guards against. Route-model binding, ResourceData
 * hydration, and policy reads all need controller-level Model access; the
 * doctrine line is "Controllers may READ Models, but MUST NOT mutate them."
 *
 * Supersedes the consumer-side string-match Pest arch tests (kendo
 * `backend/tests/Arch/ControllersTest.php`, ublgenie + entreezuil
 * `tests/Arch/ControllersTest.php`, ISMS
 * `backend/tests/Architecture/ControllerCurrentUserTest.php`'s 8-pattern
 * Eloquent-method block). The string-match shape catches `->save(`, `->update([`,
 * `->delete(`, `->forceDelete(` but cannot discriminate `Model::create()` from
 * `Response::create()`, `Collection::push()` from `Model::push()`, or
 * `->update($vars)` without an inline array literal — the type-aware AST
 * inspection here closes those gaps.
 *
 * Algorithm:
 *
 *   1. Namespace gate — the class namespace must start with ANY of the
 *      configured `controllerNamespacePrefixes` (default
 *      `['App\Http\Controllers']`). Sub-namespaces (kendo's
 *      `App\Http\Controllers\Central\*`) pass naturally via `str_starts_with`.
 *      A consumer that ships controllers under a divergent namespace (e.g.
 *      emmie's `App\Http\Client\Controllers` / `App\Http\Admin\Controllers`)
 *      opts them in by adding the prefix to `controllerNamespacePrefixes` in
 *      its `phpstan.neon` — mirrors the `formRequestBaseClass` /
 *      `resourceDataBaseClass` parameter precedent.
 *   2. **MethodCall:** resolve the receiver expression's type via
 *      `$scope->getType($node->var)`. Fire if the type is a subtype of
 *      `Illuminate\Database\Eloquent\Model` OR a subtype of
 *      `Illuminate\Database\Eloquent\Builder` (the generic parameter is not
 *      unwrapped — `ObjectType::isSuperTypeOf()` handles `Builder<User>` as a
 *      subtype of the unparameterized `Builder` cleanly without brittle
 *      generic introspection). Method name must be in the blocklist.
 *   3. **StaticCall:** resolve the class name via `$scope->resolveName()`. Fire
 *      if the FQCN is a Model subclass and the method name is in the blocklist.
 *      The class-name resolution path covers `User::create([...])`,
 *      `User::destroy($id)`, `User::updateOrCreate(...)`. Class-name expressions
 *      that are not a literal `Name` node (`$class::create(...)`) are out of
 *      scope.
 *
 * Implementation note: `getNodeType()` returns `CallLike::class` so PHPStan
 * hands each call node a method-level flow scope — mirrors `LogRule` /
 * `EnforceCurrentUserAttributeRule`. An earlier revision registered on `Class_`
 * and walked each method body manually, resolving receiver types against the
 * CLASS-entry scope; that scope carries no flow-derived knowledge of
 * method-local variables, so receivers born inside the body
 * (`$m = new Model; $m->save();`, `$m = Model::where(...)->firstOrFail(); $m->delete();`,
 * a `Builder` held in a local var) resolved to `mixed` and NEVER fired — only
 * receivers typed from a method signature (typed parameters) matched. Per-node
 * registration closes that blind spot: PHPStan supplies the flow scope, so
 * local-variable receivers resolve to their real Model / Builder types. The
 * containing-controller FQCN for the message comes from
 * `$scope->getClassReflection()?->getName()` at the call site (non-null once the
 * namespace gate has passed, since the gate implies an in-class scope).
 *
 * Method-name blocklist — full ADR-0011 + ADR-0019 mutation surface (24 entries):
 *
 *   save, saveOrFail, saveQuietly,
 *   update, updateOrFail, updateQuietly,
 *   delete, deleteOrFail,
 *   forceDelete, forceDeleteQuietly,
 *   destroy, create, createOrFirst, firstOrCreate, updateOrCreate,
 *   fill, forceFill,
 *   push, pushQuietly,
 *   restore, restoreQuietly, restoreOrCreate,
 *   touch, touchQuietly
 *
 * Suppression: standard PHPStan inline-ignore mechanism on the rule's
 * identifier `forbidEloquentMutationInControllers.eloquentMutationInController`.
 *
 * Out of scope:
 *
 *   - Non-`App\Http\Controllers\*` namespaces — Actions, Services, Jobs,
 *     console commands, middleware all freely call Eloquent persistence APIs.
 *   - Non-Eloquent receivers — `$service->save()`, `$collection->push($item)`,
 *     `Response::create(...)` all pass the type gate.
 *   - Read methods on Models — `User::find($id)`, `$user->where(...)->get()`
 *     are deliberately allowed; controllers reading Models is necessary for
 *     route-model binding, ResourceData hydration, and policy checks.
 *   - Dynamic method names (`$method = 'save'; $model->{$method}()`) — would
 *     need value-flow analysis. Acceptable miss; rely on reviewer.
 *
 * @implements Rule<CallLike>
 */
final class ForbidEloquentMutationInControllersRule implements Rule
{
    /**
     * Eloquent persistence-API method names that mutate model or table state.
     * Reads are deliberately omitted — controllers reading Models is necessary
     * for route-model binding, ResourceData hydration, and policy checks.
     *
     * @var list<string>
     */
    private const array MUTATION_METHODS = [
        'save', 'saveOrFail', 'saveQuietly',
        'update', 'updateOrFail', 'updateQuietly',
        'delete', 'deleteOrFail',
        'forceDelete', 'forceDeleteQuietly',
        'destroy', 'create', 'createOrFirst', 'firstOrCreate', 'updateOrCreate',
        'fill', 'forceFill',
        'push', 'pushQuietly',
        'restore', 'restoreQuietly', 'restoreOrCreate',
        'touch', 'touchQuietly',
    ];

    /**
     * @param list<string> $controllerNamespacePrefixes namespace prefixes whose
     *                                                  classes are treated as
     *                                                  controllers (match via
     *                                                  `str_starts_with`); the
     *                                                  default reproduces the
     *                                                  canonical
     *                                                  `App\Http\Controllers`
     *                                                  gate. Consumers with
     *                                                  sub-namespaced
     *                                                  controllers add their
     *                                                  prefixes from config.
     */
    public function __construct(
        private array $controllerNamespacePrefixes = ['App\Http\Controllers'],
    ) {}

    public function getNodeType(): string
    {
        return CallLike::class;
    }

    public function processNode(Node $node, Scope $scope): array
    {
        $namespace = $scope->getNamespace();

        if ($namespace === null || !$this->namespaceIsController($namespace)) {
            return [];
        }

        $modelType = new ObjectType(Model::class);
        $builderType = new ObjectType(EloquentBuilder::class);

        if ($node instanceof MethodCall) {
            $violation = $this->checkInstanceCall($node, $scope, $modelType, $builderType);
        } elseif ($node instanceof StaticCall) {
            $violation = $this->checkStaticCall($node, $scope, $modelType);
        } else {
            return [];
        }

        if ($violation === null) {
            return [];
        }

        // At a call-site scope the class reflection resolves (the namespace gate
        // already implies an in-class scope). Guard defensively — a call outside
        // any class yields no controller FQCN, so there is nothing to report.
        $classReflection = $scope->getClassReflection();

        if ($classReflection === null) {
            return [];
        }

        return [$this->buildError($classReflection->getName(), $violation)];
    }

    /**
     * True when `$namespace` starts with ANY configured controller prefix.
     * Sub-namespaces (`App\Http\Controllers\Central`) match the canonical
     * `App\Http\Controllers` prefix naturally.
     */
    private function namespaceIsController(string $namespace): bool
    {
        foreach ($this->controllerNamespacePrefixes as $prefix) {
            if (str_starts_with($namespace, $prefix)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Match `$model->mutation(...)` or `$builder->mutation(...)`. Receiver type
     * must be a subtype of `Illuminate\Database\Eloquent\Model` OR
     * `Illuminate\Database\Eloquent\Builder`; method name must be in the
     * blocklist.
     *
     * @return array{type: string, method: string, node: MethodCall}|null
     */
    private function checkInstanceCall(
        MethodCall $node,
        Scope $scope,
        ObjectType $modelType,
        ObjectType $builderType,
    ): ?array {
        if (!$node->name instanceof Identifier) {
            return null;
        }

        $methodName = $node->name->toString();

        if (!in_array($methodName, self::MUTATION_METHODS, true)) {
            return null;
        }

        $receiverType = $scope->getType($node->var);

        $receiverFqcn = $this->matchedReceiverFqcn($receiverType, $modelType, $builderType);

        if ($receiverFqcn === null) {
            return null;
        }

        return ['type' => $receiverFqcn, 'method' => $methodName, 'node' => $node];
    }

    /**
     * Match `User::create([...])` / `User::destroy($id)` etc. Resolves the
     * static-call class via the file's use-import map; only literal `Name`
     * class expressions are inspected. Variable class names
     * (`$class::create(...)`) and dynamic method names are out of scope.
     *
     * @return array{type: string, method: string, node: StaticCall}|null
     */
    private function checkStaticCall(StaticCall $node, Scope $scope, ObjectType $modelType): ?array
    {
        if (!$node->name instanceof Identifier) {
            return null;
        }

        $methodName = $node->name->toString();

        if (!in_array($methodName, self::MUTATION_METHODS, true)) {
            return null;
        }

        if (!$node->class instanceof Name) {
            return null;
        }

        $resolvedFqcn = $scope->resolveName($node->class);
        $resolvedType = new ObjectType($resolvedFqcn);

        if (!$modelType->isSuperTypeOf($resolvedType)->yes()) {
            return null;
        }

        return ['type' => $resolvedFqcn, 'method' => $methodName, 'node' => $node];
    }

    /**
     * Returns the matched receiver's representative FQCN (the short-name used
     * in the error message), or null if the receiver type is not a Model /
     * Builder subtype.
     */
    private function matchedReceiverFqcn(Type $receiverType, ObjectType $modelType, ObjectType $builderType): ?string
    {
        if ($modelType->isSuperTypeOf($receiverType)->yes()) {
            $referenced = $receiverType->getReferencedClasses();

            return $referenced[0] ?? Model::class;
        }

        if ($builderType->isSuperTypeOf($receiverType)->yes()) {
            $referenced = $receiverType->getReferencedClasses();

            return $referenced[0] ?? EloquentBuilder::class;
        }

        return null;
    }

    /**
     * @param array{type: string, method: string, node: MethodCall|StaticCall} $violation
     */
    private function buildError(string $classFqcn, array $violation): IdentifierRuleError
    {
        $message = sprintf(
            'Controller %s must not call Eloquent persistence method %s() on %s — delegate to an Action (ADR-0011 + ADR-0019).',
            $classFqcn,
            $violation['method'],
            $this->shortName($violation['type']),
        );

        return RuleErrorBuilder::message($message)
            ->identifier('forbidEloquentMutationInControllers.eloquentMutationInController')
            ->line($violation['node']->getStartLine())
            ->build();
    }

    private function shortName(string $fqcn): string
    {
        $pos = mb_strrpos($fqcn, '\\');

        if ($pos === false) {
            return $fqcn;
        }

        return mb_substr($fqcn, $pos + 1);
    }
}
