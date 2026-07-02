<?php

declare(strict_types = 1);

namespace ScriptDevelopment\PhpstanWarroomRules\Rules;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\JsonResource;
use PhpParser\Node;
use PhpParser\Node\Arg;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\New_;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\IdentifierRuleError;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;
use PHPStan\Type\ObjectType;

use function str_starts_with;

/**
 * Forbids wrapping a `JsonResource` in `response()->json(...)` or
 * `new JsonResponse(...)` inside a class whose FQCN starts with
 * `App\Http\Controllers`. A `JsonResource` is already a `Responsable` — Laravel
 * serializes it to a JSON response on its own. Wrapping one in an explicit JSON
 * response double-wraps the payload (`{"data": {...}}` becomes the wrapped body
 * of another response) and discards the resource's own response shaping. Return
 * the resource directly instead: `return XxxResource::fromModel($model);`
 * (HTTP 200).
 *
 * Doctrine source: war-room §Architectural Principles — Explicit over implicit
 * (#1) + ADR-0009 (Unified ResourceData Pattern — resources own their own
 * response serialization).
 *
 * Detection is type-aware (mandatory — a blanket string-ban on
 * `response()->json(...)` would false-positive on the overwhelmingly common
 * legitimate sites that wrap a plain array / DTO / scalar / message envelope,
 * and on `response()->json(null, 204)`). The rule fires ONLY when the first
 * argument's resolved type is a subtype of
 * `Illuminate\Http\Resources\Json\JsonResource`. Two AST shapes are inspected:
 *
 *   1. `MethodCall` named `json` whose receiver is the `response()` helper
 *      `FuncCall` (AST-shape match — the helper's `ResponseFactory` return type
 *      is unloaded in stub-only analysis environments, mirroring how
 *      `EnforceCurrentUserAttributeRule` matches the `auth()` helper).
 *   2. `New_` of `Illuminate\Http\JsonResponse` (FQCN resolved via
 *      `$scope->resolveName()`).
 *
 * Named-envelope edge (decided: EXCLUDE). A resource (or resource collection)
 * nested under a named array key — e.g.
 * `response()->json(['registrations' => LockedRegistrationResource::collect(...)])`
 * — is a deliberate envelope, NOT a bare double-wrap. The first argument is an
 * `Array_`, whose type is `array<...>`, not a `JsonResource` subtype, so the
 * type gate naturally lets it through. This is intentional: the envelope is the
 * author's chosen response contract, and unwrapping it would change the wire
 * shape. Only the bare `response()->json($resource)` / `new JsonResponse($resource)`
 * form fires.
 *
 * Out of scope (deliberately):
 *
 *   - Non-`App\Http\Controllers\*` namespaces.
 *   - Non-JsonResource first arguments (arrays, DTOs, scalars, `null`) — the
 *     type gate passes them.
 *   - A resource nested inside any array / object envelope (see named-envelope
 *     edge above).
 *   - `response()->json($resource, 201)` style status overrides: there is no
 *     sanctioned resource-with-non-200 idiom yet (a future investigation), so
 *     the compliant path the message points at is the 200 form. The rule still
 *     fires on the wrap regardless of the status argument — the wrap is the
 *     violation, not the status.
 *
 * Suppression: standard PHPStan inline-ignore mechanism on the rule's
 * identifier `forbidResourceWrappedInJsonResponse.resourceWrapped`.
 *
 * Controller-namespace gate mirrors `ForbidEloquentMutationInControllersRule` /
 * `EnforceCurrentUserAttributeRule`: the class namespace must start with ANY of
 * the configured `controllerNamespacePrefixes` (default
 * `['App\Http\Controllers']`, reproducing the prior hardcoded gate byte-for-
 * byte; sub-namespaces pass naturally via `str_starts_with`). A consumer that
 * ships controllers under a divergent namespace (e.g. emmie's
 * `App\Http\Client\Controllers` / `App\Http\Admin\Controllers`) opts them in by
 * adding the prefix to `controllerNamespacePrefixes` in its `phpstan.neon`.
 *
 * @implements Rule<Node>
 */
final class ForbidResourceWrappedInJsonResponseRule implements Rule
{
    private const string JSON_RESOURCE_CLASS = JsonResource::class;

    private const string JSON_RESPONSE_CLASS = JsonResponse::class;

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
        return Node::class;
    }

    public function processNode(Node $node, Scope $scope): array
    {
        $namespace = $scope->getNamespace();

        if ($namespace === null || !$this->namespaceIsController($namespace)) {
            return [];
        }

        $payloadArg = $this->matchedPayloadArg($node, $scope);

        if ($payloadArg === null) {
            return [];
        }

        $payloadType = $scope->getType($payloadArg->value);

        if (!(new ObjectType(self::JSON_RESOURCE_CLASS))->isSuperTypeOf($payloadType)->yes()) {
            return [];
        }

        return [$this->buildError($node)];
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
     * Returns the first (payload) argument of a `response()->json(...)` call or
     * a `new JsonResponse(...)` expression, or null if the node is neither.
     */
    private function matchedPayloadArg(Node $node, Scope $scope): ?Arg
    {
        if ($node instanceof MethodCall) {
            return $this->responseJsonPayloadArg($node);
        }

        if ($node instanceof New_) {
            return $this->jsonResponsePayloadArg($node, $scope);
        }

        return null;
    }

    /**
     * Match `response()->json($payload, ...)`. Receiver must be the `response()`
     * helper FuncCall; method name must be `json`. AST-shape match (the helper's
     * return type is unloaded in stub-only environments).
     */
    private function responseJsonPayloadArg(MethodCall $node): ?Arg
    {
        if (!$node->name instanceof Identifier || $node->name->toString() !== 'json') {
            return null;
        }

        if (!$node->var instanceof FuncCall || !$node->var->name instanceof Name) {
            return null;
        }

        if ($node->var->name->toString() !== 'response') {
            return null;
        }

        return $this->firstArg($node->getArgs());
    }

    /**
     * Match `new JsonResponse($payload, ...)`. Class name resolved via the use-
     * import map; only literal `Name` class expressions are inspected.
     */
    private function jsonResponsePayloadArg(New_ $node, Scope $scope): ?Arg
    {
        if (!$node->class instanceof Name) {
            return null;
        }

        if ($scope->resolveName($node->class) !== self::JSON_RESPONSE_CLASS) {
            return null;
        }

        return $this->firstArg($node->getArgs());
    }

    /**
     * @param array<array-key, Arg> $args
     */
    private function firstArg(array $args): ?Arg
    {
        return $args[0] ?? null;
    }

    private function buildError(Node $node): IdentifierRuleError
    {
        return RuleErrorBuilder::message(
            'Controllers must not wrap a JsonResource in response()->json(...) / new JsonResponse(...) — a resource is already a Responsable. '
            . 'Return the resource directly: return XxxResource::fromModel($model);',
        )
            ->identifier('forbidResourceWrappedInJsonResponse.resourceWrapped')
            ->line($node->getStartLine())
            ->build();
    }
}
