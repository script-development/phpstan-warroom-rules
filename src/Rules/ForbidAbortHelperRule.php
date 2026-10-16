<?php

declare(strict_types = 1);

namespace ScriptDevelopment\PhpstanWarroomRules\Rules;

use PhpParser\Node;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Name;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;
use ScriptDevelopment\PhpstanWarroomRules\Support\NamespacePrefix;

use function in_array;

/**
 * Forbids usage of abort(), abort_if(), and abort_unless() helper functions.
 *
 * The remedy depends on the layer. Outside `App\Actions` the message prescribes
 * an explicit HTTP exception (e.g., NotFoundHttpException). Inside `App\Actions`
 * that advice would trip `ForbidHttpExceptionInActionsRule`, so the message
 * prescribes a domain exception the exception renderer maps to a status.
 *
 * Doctrine source: war-room §Architectural Principles — Explicit over implicit.
 *
 * @implements Rule<FuncCall>
 */
final class ForbidAbortHelperRule implements Rule
{
    private const array FORBIDDEN_FUNCTIONS = [
        'abort',
        'abort_if',
        'abort_unless',
    ];

    public function getNodeType(): string
    {
        return FuncCall::class;
    }

    public function processNode(Node $node, Scope $scope): array
    {
        if (!$node->name instanceof Name) {
            return [];
        }

        $functionName = $node->name->toString();

        if (!in_array($functionName, self::FORBIDDEN_FUNCTIONS, true)) {
            return [];
        }

        $namespace = $scope->getNamespace();
        $remedy = $namespace !== null && NamespacePrefix::contains(NamespacePrefix::ACTIONS, $namespace)
            ? 'Actions must not raise HTTP-layer errors — throw a domain exception the exception renderer maps to a status.'
            : 'Throw a proper HTTP exception instead (e.g., NotFoundHttpException, UnauthorizedHttpException, HttpException).';

        return [
            RuleErrorBuilder::message("Use of {$functionName}() is forbidden. {$remedy}")
                ->identifier('forbidAbortHelper.abortUsed')
                ->build(),
        ];
    }
}
