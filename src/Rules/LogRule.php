<?php

declare(strict_types = 1);

namespace ScriptDevelopment\PhpstanWarroomRules\Rules;

use PhpParser\Node;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Identifier;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;

use function in_array;

/**
 * Forbids update() / delete() calls on classes whose name contains "Log" or "logs".
 *
 * Doctrine source: ADR-0001 §Append-only — audit records have no UPDATE, no DELETE.
 *
 * Substring matching is intentionally broad. False positives on classes like
 * "Catalog", "Blog", "Terminology", or domain models that include "log" in the
 * name should be suppressed per-territory via phpstan.neon ignoreErrors,
 * scoped to the offending path.
 *
 * @implements Rule<MethodCall>
 */
final class LogRule implements Rule
{
    private const array FORBIDDEN_METHODS = ['delete', 'update'];

    public function getNodeType(): string
    {
        return MethodCall::class;
    }

    public function processNode(Node $node, Scope $scope): array
    {
        if (
            !$node->name instanceof Identifier
            || !in_array($node->name->toString(), self::FORBIDDEN_METHODS, true)
        ) {
            return [];
        }

        $calledOnType = $scope->getType($node->var);

        $referencedClasses = $calledOnType->getReferencedClasses();

        foreach ($referencedClasses as $referencedClass) {
            if (
                mb_stripos($referencedClass, 'Log') !== false
                || mb_stripos($referencedClass, 'logs') !== false
            ) {
                return [
                    RuleErrorBuilder::message('Logs should not be updated or deleted.')
                        ->identifier('logRule.logModification')
                        ->build(),
                ];
            }
        }

        return [];
    }
}
