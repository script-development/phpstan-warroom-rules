<?php

declare(strict_types = 1);

namespace ScriptDevelopment\PhpstanWarroomRules\Rules;

use Illuminate\Http\JsonResponse;
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

use function str_starts_with;

/**
 * Forbids constructing the base `Illuminate\Http\JsonResponse` — or its
 * `response()->json(...)` factory twin — with an ARRAY payload inside a class
 * whose FQCN starts with one of the configured `controllerNamespacePrefixes`.
 * A response shape assembled from an inline array (or an array-typed variable)
 * has no contract: it names no fields at the type level, so the frontend and
 * every future reader re-derive the shape by reading the controller body.
 * Response shapes belong to a Resource / ResourceData or a dedicated
 * `JsonResponse` subclass (`NoContentResponse`, `ValidationErrorResponse`, …),
 * which name their fields and own their serialization.
 *
 * Doctrine source: ADR-0009 (Unified ResourceData Pattern). Seed: kendo PR
 * #1653 (KD-0220 central-user 2FA) — `TwoFactorController::status()` returned
 * `new JsonResponse(['enabled' => …, 'has_recovery_codes' => …])`
 * ("Voor consistentie opzich mooier om in deze controller ook Resources terug
 * te sturen"), and the sibling `enable()` laundered the same violation through a
 * variable (`$result = $action->execute(...); return new JsonResponse($result);`).
 *
 * Sibling / inverse of `ForbidResourceWrappedInJsonResponseRule`. Both police
 * the same `JsonResponse` × payload boundary from opposite directions and share
 * the two-AST-shape match + the `controllerNamespacePrefixes` gate:
 *
 *   - `ForbidResourceWrappedInJsonResponseRule` fires when the payload IS a
 *     `JsonResource` (a resource is already a `Responsable`, do not double-wrap).
 *   - THIS rule fires when the payload is a bare ARRAY (a struct that should be
 *     a Resource / DTO / dedicated response, not an untyped key bag).
 *
 * The two are disjoint by construction: a `JsonResource`-typed payload is not an
 * array type and vice versa, so a given call site fires at most one of them.
 *
 * Detection (type-aware — a blanket ban on `new JsonResponse(...)` would
 * criminalize the compliant DTO / Resource / message-object payloads):
 *
 *   1. `New_` of EXACTLY `Illuminate\Http\JsonResponse` — resolved via
 *      `$scope->resolveName()`, FQCN equality, NOT `isSuperTypeOf`. Subclasses
 *      (`NoContentResponse`, `ValidationErrorResponse`, territory-local
 *      dedicated responses) are the compliant fix; matching by supertype would
 *      criminalize it.
 *   2. `MethodCall` named `json` whose receiver is the `response()` helper
 *      `FuncCall` (AST-shape match — the helper's `ResponseFactory` return type
 *      is unloaded in stub-only analysis environments, mirroring how
 *      `EnforceCurrentUserAttributeRule` matches the `auth()` helper and how the
 *      sibling rule matches `response()->json`). The `json` factory always
 *      builds a base `JsonResponse`, so it is in scope for the same reason.
 *
 * Payload gate (first constructor / method argument):
 *
 *   - No arguments, or a payload whose resolved type is NOT an array → pass.
 *     A `null` first argument (`new JsonResponse(null, 204)`) is not an array
 *     type, so it passes naturally — bare / empty responses are fine, and
 *     steering `null, 204` toward `NoContentResponse` is a different rule's job.
 *   - First argument's resolved type is an array
 *     (`$scope->getType($arg)->isArray()->yes()`) → error. This catches both
 *     inline literals (`new JsonResponse(['enabled' => …])`) and array-typed
 *     variables (`new JsonResponse($result)` where `$result` is array-typed —
 *     the same violation laundered through a variable).
 *   - Anything else (Resource, DTO, `JsonSerializable`, `Arrayable`, mixed /
 *     unknown) → pass. Uncertain types stay silent — false negatives are
 *     acceptable, false positives are not (ADR-0021 posture).
 *
 * Out of scope (deliberately):
 *
 *   - Classes outside the configured controller namespace prefixes.
 *   - `JsonResponse` SUBCLASSES with an array payload — they are the sanctioned
 *     fix (a dedicated response owns its shape); exact-class match keeps them out.
 *   - `JsonResponse::fromJsonString(...)` — a static factory over a raw JSON
 *     string, not an array payload; a separate (rare) idiom left uncovered.
 *   - Non-array payloads of any kind (the type gate passes them).
 *
 * Rollout position — the rule discovers violations by PAYLOAD SHAPE, and does
 * NOT distinguish a domain-resource shape (the seed: `{secret, qr_code}`,
 * `{enabled, has_recovery_codes}`) from a single-key status/ack payload
 * (`['message' => 'Webhook received']`, `['message' => 'Invalid or expired
 * exchange token.']`). Both are array payloads; both fire. This is a deliberate
 * disposition, not an oversight: the noise is accepted as the cost of the
 * discovery-by-shape approach (consistent with the denylist-inversion posture of
 * `EnforceAuditModelProtectionsRule`). The two escape hatches considered were
 * rejected:
 *   - a key-count threshold ("fire only above N keys") punches a false-negative
 *     hole — a genuine single-field resource (`{token: ...}`) would silently pass;
 *   - an ack/error-shape allowlist is territory-specific config, which the
 *     package convention forbids hardcoding in a rule body (ADR-0021
 *     §No territory-specific exceptions).
 * Both violation classes remediate the same way: a dedicated fleet-wide
 * `MessageResponse` / `ErrorResponse` `JsonResponse` subclass (cheap, reusable —
 * the sanctioned escape hatch a subclass already is), or baseline-suppress the
 * throwaway acks during the standing SUPPRESS-ONLY / baseline-absorb rollout.
 * Consumers sizing adoption should SEPARATE the ack-noise class from the
 * resource-shape class — the raw call-site count (kendo: ~33 sites / 18
 * controllers) conflates the two and overstates the genuine ADR-0009
 * remediation surface.
 *
 * Controller-namespace gate mirrors `ForbidResourceWrappedInJsonResponseRule` /
 * `ForbidEloquentMutationInControllersRule` / `EnforceCurrentUserAttributeRule`:
 * the class namespace must start with ANY configured prefix (default
 * `['App\Http\Controllers']`, reproducing the canonical hardcoded gate;
 * sub-namespaces pass via `str_starts_with`). A consumer with divergent
 * controller namespaces (emmie's `App\Http\Client\Controllers` /
 * `App\Http\Admin\Controllers`) opts them in via `controllerNamespacePrefixes`.
 *
 * Suppression: standard PHPStan inline-ignore mechanism on the rule's
 * identifier `forbidInlineArrayJsonResponseInControllers.arrayPayload`.
 *
 * @implements Rule<Node>
 */
final class ForbidInlineArrayJsonResponseInControllersRule implements Rule
{
    private const string JSON_RESPONSE_CLASS = JsonResponse::class;

    /**
     * @param list<string> $controllerNamespacePrefixes namespace prefixes whose
     *                                                  classes are treated as
     *                                                  controllers (match via
     *                                                  `str_starts_with`); the
     *                                                  default reproduces the
     *                                                  canonical
     *                                                  `App\Http\Controllers`
     *                                                  gate. Shared with the
     *                                                  other controller-scoped
     *                                                  rules via one config knob.
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

        if (!$scope->getType($payloadArg->value)->isArray()->yes()) {
            return [];
        }

        return [$this->buildError($node)];
    }

    /**
     * True when `$namespace` starts with ANY configured controller prefix.
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
     * Returns the first (payload) argument of a matched `new JsonResponse(...)`
     * or `response()->json(...)` node, or null if the node is neither (or has
     * no arguments).
     */
    private function matchedPayloadArg(Node $node, Scope $scope): ?Arg
    {
        if ($node instanceof New_) {
            return $this->jsonResponsePayloadArg($node, $scope);
        }

        if ($node instanceof MethodCall) {
            return $this->responseJsonPayloadArg($node);
        }

        return null;
    }

    /**
     * Match `new JsonResponse($payload, ...)`. Exact-FQCN match (subclasses are
     * the compliant fix and stay out of scope); only literal `Name` class
     * expressions are inspected.
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
     * @param array<array-key, Arg> $args
     */
    private function firstArg(array $args): ?Arg
    {
        return $args[0] ?? null;
    }

    private function buildError(Node $node): IdentifierRuleError
    {
        return RuleErrorBuilder::message(
            'Controllers must not construct JsonResponse with an array payload — '
            . 'return a Resource/ResourceData or a dedicated JsonResponse subclass (ADR-0009).',
        )
            ->identifier('forbidInlineArrayJsonResponseInControllers.arrayPayload')
            ->line($node->getStartLine())
            ->build();
    }
}
