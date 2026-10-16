<?php

declare(strict_types = 1);

namespace ScriptDevelopment\PhpstanWarroomRules\Rules;

use PhpParser\Node;
use PhpParser\Node\Expr\Throw_;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\IdentifierRuleError;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;
use PHPStan\Type\ObjectType;
use ScriptDevelopment\PhpstanWarroomRules\Support\NamespacePrefix;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

/**
 * Forbids throwing a `Symfony\Component\HttpKernel\Exception\*` HTTP-layer
 * exception (the `HttpException` family — `HttpException` itself and every
 * subclass: `NotFoundHttpException`, `AccessDeniedHttpException`,
 * `UnprocessableEntityHttpException`, etc.) from inside a class whose FQCN
 * sits in or under `App\Actions`. HTTP status concerns belong to the HTTP layer
 * (controllers / FormRequests / exception renderers), not the domain layer —
 * an Action that throws a 422 has reached past its boundary into transport.
 * A uniqueness rule belongs in the FormRequest; a domain failure throws a
 * custom domain exception the renderer maps to a status.
 *
 * Doctrine source: war-room §Architectural Principles — Explicit over implicit
 * (#1) + Form Request → DTO → Action pipeline (#3).
 *
 * This rule is the type-aware sibling of `ForbidAbortHelperRule`. That rule
 * bans the `abort()` / `abort_if()` / `abort_unless()` helpers (whose message
 * even *recommends* `throw new HttpException` — correct for controllers, wrong
 * for Actions); this rule closes the matching gap on the direct
 * `throw new HttpException(...)` form inside Actions. The two together cover
 * both ways of raising an HTTP-layer error from the domain layer.
 *
 * Detection is type-aware: the thrown expression's resolved type must be a
 * subtype of `Symfony\Component\HttpKernel\Exception\HttpExceptionInterface`
 * (which every member of the `HttpException` family implements). This catches:
 *
 *   - subclass throws the import-checking arch test would miss
 *     (`throw new NotFoundHttpException(...)`),
 *   - fully-qualified throws with no `use` import,
 *   - throws of a variable / factory-returned value whose static type is an
 *     HTTP-exception subtype.
 *
 * Out of scope (deliberately):
 *
 *   - `Illuminate\Validation\ValidationException` — Actions legitimately throw
 *     `new ValidationException($validator)` for stateful / cross-field
 *     validation that cannot live in a static FormRequest. It returns a
 *     structured error bag, not a raw HTTP-layer leak, and is NOT a member of
 *     the Symfony `HttpException` family, so this rule never fires on it.
 *   - Non-`App\Actions\*` namespaces — controllers, FormRequests, exception
 *     renderers, middleware all legitimately raise HTTP-layer exceptions.
 *   - The `abort()` helper family — covered by `ForbidAbortHelperRule`.
 *
 * Suppression: standard PHPStan inline-ignore mechanism on the rule's
 * identifier `forbidHttpExceptionInActions.httpExceptionInAction`.
 *
 * Action-namespace assumption mirrors `ForbidDatabaseManagerInActionsRule` /
 * `EnforceAuditTransactionScopeRule`: `App\Actions` prefix via
 * `$scope->getNamespace()` + `NamespacePrefix::contains()`. If a future consumer ships
 * Actions under a divergent namespace, lift this into a parameter per the
 * `EnforceResourceDataValidatorOptInRule` precedent.
 *
 * @implements Rule<Throw_>
 */
final class ForbidHttpExceptionInActionsRule implements Rule
{
    private const string HTTP_EXCEPTION_INTERFACE = HttpExceptionInterface::class;

    public function getNodeType(): string
    {
        return Throw_::class;
    }

    public function processNode(Node $node, Scope $scope): array
    {
        $namespace = $scope->getNamespace();

        if ($namespace === null || !NamespacePrefix::contains(NamespacePrefix::ACTIONS, $namespace)) {
            return [];
        }

        $thrownType = $scope->getType($node->expr);

        if (!(new ObjectType(self::HTTP_EXCEPTION_INTERFACE))->isSuperTypeOf($thrownType)->yes()) {
            return [];
        }

        return [$this->buildError($node)];
    }

    private function buildError(Throw_ $node): IdentifierRuleError
    {
        return RuleErrorBuilder::message(
            'Actions must not throw an HTTP-layer exception (Symfony\Component\HttpKernel\Exception\HttpException family). '
            . 'HTTP status concerns belong to the HTTP layer — put a uniqueness rule in the FormRequest, or throw a custom domain exception the renderer maps to a status.',
        )
            ->identifier('forbidHttpExceptionInActions.httpExceptionInAction')
            ->line($node->getStartLine())
            ->build();
    }
}
