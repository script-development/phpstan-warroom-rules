<?php

declare(strict_types = 1);

namespace ScriptDevelopment\PhpstanWarroomRules\Rules;

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

use function in_array;

/**
 * Forbids update / delete / forceDelete / forceDeleteQuietly instance calls and
 * destroy / forceDestroy static calls on classes whose name contains "Log" or
 * "logs".
 *
 * Doctrine source: ADR-0001 §Append-only — audit records have no UPDATE, no DELETE.
 *
 * `forceDelete` / `forceDeleteQuietly` are covered alongside `delete` because on
 * a SoftDeletes-bearing model `->delete()` is a no-op against the underlying row
 * and `->forceDelete()` is the only call that actually purges. The rule's teeth
 * shouldn't depend on the migration-time convention that audit-log models never
 * adopt SoftDeletes.
 *
 * `Model::destroy()` / `Model::forceDestroy()` static-call shapes are covered
 * for the same reason: on a non-soft-delete log model `Model::destroy([1])`
 * does purge, and `Model::forceDestroy([1])` always purges. Both shapes share
 * the `logRule.logModification` identifier so consumer suppressions cover the
 * whole rule with one entry.
 *
 * Substring matching is intentionally broad. False positives on classes like
 * "Catalog", "Blog", "Terminology", or domain models that include "log" in the
 * name should be suppressed per-territory via phpstan.neon ignoreErrors,
 * scoped to the offending path.
 *
 * `DB::table('logs')->truncate()` is intentionally not covered — the receiver
 * type is `Illuminate\Database\Query\Builder` (no Log-named class reference),
 * the table name lives in a string argument, and matching that requires a
 * shape-specific rule that inspects the call chain. Tracked separately.
 *
 * @implements Rule<CallLike>
 */
final class LogRule implements Rule
{
    private const array FORBIDDEN_INSTANCE_METHODS = ['delete', 'forceDelete', 'forceDeleteQuietly', 'update'];

    private const array FORBIDDEN_STATIC_METHODS = ['destroy', 'forceDestroy'];

    public function getNodeType(): string
    {
        return CallLike::class;
    }

    public function processNode(Node $node, Scope $scope): array
    {
        if ($node instanceof MethodCall) {
            return $this->processMethodCall($node, $scope);
        }

        if ($node instanceof StaticCall) {
            return $this->processStaticCall($node, $scope);
        }

        return [];
    }

    /**
     * @return list<IdentifierRuleError>
     */
    private function processMethodCall(MethodCall $node, Scope $scope): array
    {
        if (
            !$node->name instanceof Identifier
            || !in_array($node->name->toString(), self::FORBIDDEN_INSTANCE_METHODS, true)
        ) {
            return [];
        }

        return $this->errorIfReceiverIsLog($scope->getType($node->var)->getReferencedClasses());
    }

    /**
     * @return list<IdentifierRuleError>
     */
    private function processStaticCall(StaticCall $node, Scope $scope): array
    {
        if (
            !$node->name instanceof Identifier
            || !in_array($node->name->toString(), self::FORBIDDEN_STATIC_METHODS, true)
        ) {
            return [];
        }

        $referencedClasses = $node->class instanceof Name
            ? [$scope->resolveName($node->class)]
            : $scope->getType($node->class)->getReferencedClasses();

        return $this->errorIfReceiverIsLog($referencedClasses);
    }

    /**
     * @param array<int, string> $referencedClasses
     *
     * @return list<IdentifierRuleError>
     */
    private function errorIfReceiverIsLog(array $referencedClasses): array
    {
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
