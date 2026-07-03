<?php

declare(strict_types = 1);

namespace ScriptDevelopment\PhpstanWarroomRules\Rules;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use PhpParser\Node;
use PhpParser\Node\Expr\CallLike;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\IdentifierRuleError;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;
use PHPStan\Type\ObjectType;

use function sprintf;
use function str_starts_with;

/**
 * Flags Request::user() / Auth::user() / auth()->user() calls inside controller methods.
 * Use the #[\Illuminate\Container\Attributes\CurrentUser] container attribute on the
 * method parameter instead — eliminates the nullable-then-assert dance.
 *
 * Scoped to classes whose namespace starts with ANY of the configured
 * `controllerNamespacePrefixes` (default `['App\Http\Controllers']`). A
 * consumer that ships controllers under a divergent namespace (e.g. emmie's
 * `App\Http\Client\Controllers` / `App\Http\Admin\Controllers`) opts them in
 * by adding the prefix to `controllerNamespacePrefixes` in its `phpstan.neon`
 * — mirrors the `formRequestBaseClass` / `resourceDataBaseClass` parameter
 * precedent. FormRequest (`App\Http\Requests`) is excluded by design —
 * container-attribute injection does not apply to FormRequest::rules() /
 * toDto() / authorize() invocations.
 * Middleware (`App\Http\Middleware`), services, Actions (`App\Actions`), jobs,
 * and console commands are likewise out of scope: each context has its own
 * canonical resolution path (constructor DI for Actions, authenticated payload
 * threading for jobs, etc.).
 *
 * Detection (three call shapes branch in `processNode`):
 *   1. `MethodCall` — receiver type is a (subtype of) `Illuminate\Http\Request`
 *      and method name is `user`. Type-based via `ObjectType::isSuperTypeOf()`,
 *      mirroring `EnforceAuditSnapshotOnRetryRule::receiverIsConnectionInterface()`.
 *   2. `MethodCall` — receiver is a `FuncCall` to `auth()` and method name is
 *      `user`. AST-shape match (the helper has no class to type-check against
 *      in a stub-only fixture environment).
 *   3. `StaticCall` — class name resolves to `Illuminate\Support\Facades\Auth`
 *      and method name is `user`. FQCN comparison via `$scope->resolveName()`
 *      handles aliased imports.
 *
 * Containing-class gate (applied to all three branches): the rule fires only
 * when `$scope->getNamespace()` starts with a configured controller prefix
 * (default `App\Http\Controllers`). This
 * mirrors `ForbidEloquentMutationInControllersRule` and the canonical
 * "controllers are identified by the `App\Http\Controllers` namespace"
 * convention — consumer controllers are base-less `final` classes with no
 * `extends Controller`, so an ancestry walk catches nothing. Out-of-scope
 * namespaces (FormRequest in `App\Http\Requests`, middleware in
 * `App\Http\Middleware`, Actions in `App\Actions`, jobs, services) return
 * silently because their namespaces do not start with the controller prefix.
 *
 * Implementation note: `getNodeType()` returns `CallLike::class` so the rule
 * sees both `MethodCall` and `StaticCall` in a single registration — mirrors
 * the `LogRule` v0.3.0 expansion shape.
 *
 * Doctrine source: war-room §Architectural Principles — Explicit over implicit.
 *
 * @implements Rule<CallLike>
 */
final class EnforceCurrentUserAttributeRule implements Rule
{
    private const string TARGET_METHOD = 'user';

    private const string REQUEST_FQCN = Request::class;

    private const string AUTH_FACADE_FQCN = Auth::class;

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
        if (!$this->insideControllerMethod($scope)) {
            return [];
        }

        if ($node instanceof MethodCall) {
            return $this->processMethodCall($node, $scope);
        }

        if ($node instanceof StaticCall) {
            return $this->processStaticCall($node, $scope);
        }

        return [];
    }

    /**
     * Containing-class gate: the rule fires only inside methods of a class
     * whose namespace starts with `App\Http\Controllers`. FormRequest
     * (`App\Http\Requests`), middleware (`App\Http\Middleware`), services,
     * Actions (`App\Actions`), jobs, and console commands are silent because
     * their namespaces do not start with the controller prefix. Mirrors
     * `ForbidEloquentMutationInControllersRule` — consumer controllers are
     * base-less `final` classes, so an ancestry walk to a base Controller
     * catches nothing.
     */
    private function insideControllerMethod(Scope $scope): bool
    {
        $namespace = $scope->getNamespace();

        if ($namespace === null) {
            return false;
        }

        foreach ($this->controllerNamespacePrefixes as $prefix) {
            if (str_starts_with($namespace, $prefix)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<IdentifierRuleError>
     */
    private function processMethodCall(MethodCall $node, Scope $scope): array
    {
        if (!$node->name instanceof Identifier || $node->name->toString() !== self::TARGET_METHOD) {
            return [];
        }

        // Shape 1: $request->user() — receiver type is Illuminate\Http\Request
        if ($this->receiverIsRequest($node, $scope)) {
            return [$this->buildError('$request->user()')];
        }

        // Shape 2: auth()->user() — receiver is FuncCall('auth')
        if ($this->receiverIsAuthHelper($node)) {
            return [$this->buildError('auth()->user()')];
        }

        return [];
    }

    /**
     * @return list<IdentifierRuleError>
     */
    private function processStaticCall(StaticCall $node, Scope $scope): array
    {
        if (!$node->name instanceof Identifier || $node->name->toString() !== self::TARGET_METHOD) {
            return [];
        }

        if (!$node->class instanceof Name) {
            return [];
        }

        if ($scope->resolveName($node->class) !== self::AUTH_FACADE_FQCN) {
            return [];
        }

        return [$this->buildError('Auth::user()')];
    }

    /**
     * Type-based receiver gate for shape 1: `$scope->getType($node->var)` must
     * be a (subtype of) `Illuminate\Http\Request`. Mirrors the canonical type
     * pattern in `EnforceAuditSnapshotOnRetryRule::receiverIsConnectionInterface()`.
     */
    private function receiverIsRequest(MethodCall $node, Scope $scope): bool
    {
        $receiverType = $scope->getType($node->var);
        $requestType = new ObjectType(self::REQUEST_FQCN);

        return $requestType->isSuperTypeOf($receiverType)->yes();
    }

    /**
     * AST-shape gate for shape 2: the receiver is a `FuncCall` to `auth()`.
     * No type-based check because the `auth()` helper's return type
     * (AuthManager / Guard) is not loaded in stub-only fixture environments;
     * the AST shape is unambiguous on its own.
     */
    private function receiverIsAuthHelper(MethodCall $node): bool
    {
        if (!$node->var instanceof FuncCall) {
            return false;
        }

        if (!$node->var->name instanceof Name) {
            return false;
        }

        return $node->var->name->toString() === 'auth' && $node->var->args === [];
    }

    private function buildError(string $callShape): IdentifierRuleError
    {
        return RuleErrorBuilder::message(sprintf(
            'Authenticated-user resolution in controller methods uses the #[CurrentUser] container attribute. '
            . 'Add `#[\Illuminate\Container\Attributes\CurrentUser] User $user` to the method signature instead of calling %s inside the body.',
            $callShape,
        ))
            ->identifier('enforceCurrentUserAttribute.useAttributeInsteadOfRequestUser')
            ->build();
    }
}
