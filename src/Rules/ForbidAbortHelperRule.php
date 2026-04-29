<?php

declare(strict_types = 1);

namespace ScriptDevelopment\PhpstanWarroomRules\Rules;

use PhpParser\Node;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Name;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;

use function in_array;

/**
 * Forbids usage of abort(), abort_if(), and abort_unless() helper functions.
 * Use proper HTTP exceptions instead (e.g., NotFoundHttpException, UnauthorizedHttpException).
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

        return [
            RuleErrorBuilder::message(
                "Use of {$functionName}() is forbidden. Throw a proper HTTP exception instead (e.g., NotFoundHttpException, UnauthorizedHttpException, HttpException).",
            )
                ->identifier('forbidAbortHelper.abortUsed')
                ->build(),
        ];
    }
}
