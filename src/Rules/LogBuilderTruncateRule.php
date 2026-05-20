<?php

declare(strict_types = 1);

namespace ScriptDevelopment\PhpstanWarroomRules\Rules;

use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Identifier;
use PhpParser\Node\Scalar\String_;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;
use PHPStan\Type\ObjectType;

use function mb_stripos;

/**
 * Forbids `Builder->truncate()` calls where the fluent chain's most recent
 * `table()` invocation targets a Log-named table (string-literal first
 * argument containing `"log"` / `"logs"`, case-insensitive substring match).
 *
 * Doctrine source: ADR-0001 §Append-only — audit records have no UPDATE, no
 * DELETE; `truncate()` is the bluntest delete and bypasses Eloquent events,
 * observers, and audit triggers entirely.
 *
 * Sibling rule to `LogRule`. Shares the `logRule.logModification` identifier
 * so consumer `phpstan.neon` `ignoreErrors` entries cover the whole
 * append-only doctrine with one entry. Closes the structural gap deliberately
 * deferred from PR #7 (issue #8): `LogRule` matches on Log-named class
 * receivers, but `DB::table('logs')->truncate()` carries no Log-named class
 * reference — the receiver is `Illuminate\Database\Query\Builder` (or
 * `Illuminate\Database\Eloquent\Builder` for `Model::query()->truncate()`),
 * and the "Log-ness" lives in a string-literal argument to a prior `table()`
 * call in the same fluent chain.
 *
 * Detection (all three must hold to fire):
 *   1. The `MethodCall` name is `truncate`.
 *   2. The receiver type is a (subtype of) `Illuminate\Database\Query\Builder`
 *      or `Illuminate\Database\Eloquent\Builder` — type-based via
 *      `ObjectType::isSuperTypeOf()`, not property-name string matching.
 *   3. Walking back through the chain, the most recent `table()` call (whether
 *      `MethodCall` like `$db->table('x')` or `StaticCall` like
 *      `DB::table('x')`) has a `Scalar\String_` first argument whose value
 *      matches `'log'` / `'logs'` case-insensitively.
 *
 * Variable table names (`$t = 'logs'; DB::table($t)->truncate()`) are out of
 * scope — would require value-flow analysis. Acceptable miss; rely on
 * reviewer + consumer-side `phpstan.neon` `ignoreErrors`.
 *
 * Eloquent\Builder chains that set the table via `from('logs')` rather than
 * `table('logs')` are also out of scope — `from()` is Eloquent's fluent
 * vocabulary and is not recognised by the chain-walk. Model-property-driven
 * tables (`$table = 'audit_logs'` on the Model class) are likewise an
 * acceptable miss because the table name does not appear in the call chain.
 * The Eloquent\Builder receiver-type branch remains live for the rare but
 * coherent shape `$eloquentBuilder->table('logs')->truncate()`.
 *
 * Substring matching is intentionally broad. False positives on tables named
 * `catalog`, `blog`, `terminology`, or domain tables that include `log` in
 * the name should be suppressed per-territory via `phpstan.neon`
 * `ignoreErrors`, scoped to the offending path. Same convention as `LogRule`.
 *
 * @implements Rule<MethodCall>
 */
final class LogBuilderTruncateRule implements Rule
{
    private const string QUERY_BUILDER_FQCN = QueryBuilder::class;

    private const string ELOQUENT_BUILDER_FQCN = EloquentBuilder::class;

    private const array LOG_NEEDLES = ['log', 'logs'];

    private const string TABLE_SETTING_METHOD = 'table';

    public function getNodeType(): string
    {
        return MethodCall::class;
    }

    public function processNode(Node $node, Scope $scope): array
    {
        if (!$node->name instanceof Identifier || $node->name->toString() !== 'truncate') {
            return [];
        }

        if (!$this->receiverIsBuilder($node, $scope)) {
            return [];
        }

        if (!$this->chainTargetsLogNamedTable($node->var)) {
            return [];
        }

        return [
            RuleErrorBuilder::message('Logs should not be updated or deleted.')
                ->identifier('logRule.logModification')
                ->build(),
        ];
    }

    /**
     * Type-based receiver gate: `$scope->getType($node->var)` must be a
     * (subtype of) `Illuminate\Database\Query\Builder` or
     * `Illuminate\Database\Eloquent\Builder`. Mirrors the canonical pattern
     * in `EnforceAuditSnapshotOnRetryRule::receiverIsConnectionInterface()`.
     */
    private function receiverIsBuilder(MethodCall $node, Scope $scope): bool
    {
        $receiverType = $scope->getType($node->var);
        $queryBuilderType = new ObjectType(self::QUERY_BUILDER_FQCN);

        if ($queryBuilderType->isSuperTypeOf($receiverType)->yes()) {
            return true;
        }

        $eloquentBuilderType = new ObjectType(self::ELOQUENT_BUILDER_FQCN);

        return $eloquentBuilderType->isSuperTypeOf($receiverType)->yes();
    }

    /**
     * Walk back through the fluent chain looking for the most recent
     * `table()` call (`MethodCall` or `StaticCall`). Inspect its first
     * argument: fire on a Log-named `Scalar\String_`; otherwise (variable,
     * concat, function call) do not fire. If no `table()` call is found in
     * the chain, do not fire.
     */
    private function chainTargetsLogNamedTable(Expr $receiver): bool
    {
        $current = $receiver;

        while ($current instanceof MethodCall || $current instanceof StaticCall) {
            if (
                $current->name instanceof Identifier
                && $current->name->toString() === self::TABLE_SETTING_METHOD
            ) {
                return $this->firstArgIsLogNamedString($current);
            }

            if ($current instanceof MethodCall) {
                $current = $current->var;

                continue;
            }

            // StaticCall is a root — its arguments are inspected above; if
            // its name is not `table` the chain has no earlier hops to walk.
            return false;
        }

        return false;
    }

    private function firstArgIsLogNamedString(MethodCall|StaticCall $call): bool
    {
        if (!isset($call->args[0]) || !$call->args[0] instanceof Node\Arg) {
            return false;
        }

        $value = $call->args[0]->value;

        if (!$value instanceof String_) {
            return false;
        }

        foreach (self::LOG_NEEDLES as $needle) {
            if (mb_stripos($value->value, $needle) !== false) {
                return true;
            }
        }

        return false;
    }
}
