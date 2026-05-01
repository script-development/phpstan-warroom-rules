<?php

declare(strict_types = 1);

namespace ScriptDevelopment\PhpstanWarroomRules\Rules;

use Illuminate\Database\ConnectionInterface;
use PhpParser\Node;
use PhpParser\Node\Arg;
use PhpParser\Node\Expr\Assign;
use PhpParser\Node\Expr\Closure;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\New_;
use PhpParser\Node\Expr\PropertyFetch;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Identifier;
use PhpParser\Node\Stmt\Expression;
use PhpParser\Node\Stmt\If_;
use PhpParser\Node\Stmt\Nop;
use PHPStan\Analyser\Scope;
use PHPStan\Reflection\ClassReflection;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;
use PHPStan\Type\ObjectType;
use ReflectionNamedType;

use function explode;
use function file_get_contents;
use function in_array;
use function is_array;
use function is_file;
use function mb_ltrim;
use function mb_trim;
use function str_contains;
use function str_ends_with;
use function str_starts_with;

/**
 * Enforces ADR-0001 §Snapshot-on-Retry Safety: audit-writing transaction
 * closures must reset in-memory model state on the first statement so that
 * an $attempts >= 2 retry does not replay mutated state and write divergent
 * audit rows.
 *
 * Doctrine source: ADR-0001 §Snapshot-on-Retry Safety (cross-territory,
 * landed via PRs emmie #187, entreezuil #139, ublgenie #166, kendo #1029
 * across 2026-04-30 -> 2026-05-01).
 *
 * Scope: classes under `App\Actions\*` whose constructor injects an
 * entity audit logger (class short-name ends with `AuditLogger` and is
 * not `AuditLogger` / `AiOutboundLogger` / `AiMcpLogger`).
 *
 * Receiver detection is type-based (`Illuminate\Database\ConnectionInterface`
 * subtype), not property-name based — territories vary in property naming
 * (kendo uses `$this->db`, others use `$this->connection`).
 *
 * Three compliant first-statement shapes:
 *   (a) $model->refresh()                                     — updates
 *   (b) $model = $this->prop->newQuery()->findOrFail/first/firstOrFail/find or
 *       any expression terminating in ->fresh()               — deletes / fresh reads
 *   (c) $model = new SomeClass(...) or $this->prop->newInstance() — creates
 *
 * Escape hatch: `// @audit-snapshot-retry-safety: <rationale>` marker
 * preceding the transaction call (used for precondition guards, audit-
 * first-order deletes, fixed-action writers like `logReposted`).
 *
 * Implementation note: registers on `MethodCall` rather than `Class_`.
 * `Scope::getType($node->var)` requires a method-level scope to resolve
 * `$this->property` types via the active class reflection — at `Class_`
 * scope `$scope->getClassReflection()` is null and types resolve to
 * `mixed`. Discovery still scopes per-class by reading the enclosing
 * class's constructor reflection at each candidate call site.
 *
 * @implements Rule<MethodCall>
 */
final class EnforceAuditSnapshotOnRetryRule implements Rule
{
    /**
     * Channel-only logger short-names (ADR-0003) — explicit-value writes,
     * not entity snapshots, so the retry-corruption shape does not apply.
     */
    private const array EXCLUDED_LOGGER_SHORT_NAMES = [
        'AuditLogger',
        'AiOutboundLogger',
        'AiMcpLogger',
    ];

    /** Allowed terminal call names for shape (b) — fresh-fetch / fresh. */
    private const array FRESH_TERMINALS = [
        'findOrFail',
        'first',
        'firstOrFail',
        'find',
        'fresh',
    ];

    public function getNodeType(): string
    {
        return MethodCall::class;
    }

    public function processNode(Node $node, Scope $scope): array
    {
        if (!$node->name instanceof Identifier || $node->name->toString() !== 'transaction') {
            return [];
        }

        if (!isset($node->args[0]) || !$node->args[0] instanceof Arg || !$node->args[0]->value instanceof Closure) {
            return [];
        }

        $namespace = $scope->getNamespace();

        if ($namespace === null || !str_starts_with($namespace, 'App\Actions')) {
            return [];
        }

        $classReflection = $scope->getClassReflection();

        if ($classReflection === null) {
            return [];
        }

        if (!$this->classInjectsEntityAuditLogger($classReflection)) {
            return [];
        }

        if (!$this->receiverIsConnectionInterface($node, $scope)) {
            return [];
        }

        if ($this->hasExemptionMarker($node, $scope)) {
            return [];
        }

        if ($this->closureFirstStatementIsCompliant($node)) {
            return [];
        }

        return [
            RuleErrorBuilder::message(
                'First statement of audit-writing transaction closure must be one of: '
                . '$model->refresh() (update), $model = $this->prop->newQuery()->find*/first*/->fresh() (delete), '
                . 'or $model = new SomeClass(...) / $this->prop->newInstance() (create). '
                . 'Without state reset, an $attempts >= 2 retry replays mutated in-memory state. '
                . 'Precede the transaction call with `// @audit-snapshot-retry-safety: <rationale>` to opt out. '
                . 'See ADR-0001 §Snapshot-on-Retry Safety.',
            )
                ->identifier('enforceAuditSnapshotOnRetry.firstStatementMustResetState')
                ->line($node->getStartLine())
                ->build(),
        ];
    }

    /**
     * Discovery filter: at least one constructor parameter must be typed
     * with a class whose short name ends in `AuditLogger` and is not one
     * of the excluded channel/abstract loggers.
     */
    private function classInjectsEntityAuditLogger(ClassReflection $classReflection): bool
    {
        $constructor = $classReflection->getNativeReflection()->getConstructor();

        if ($constructor === null) {
            return false;
        }

        foreach ($constructor->getParameters() as $parameter) {
            $type = $parameter->getType();

            if (!$type instanceof ReflectionNamedType) {
                continue;
            }

            $typeName = $type->getName();

            $parts = explode('\\', $typeName);
            $shortName = mb_ltrim((string) end($parts), '\\');

            if (!str_ends_with($shortName, 'AuditLogger')) {
                continue;
            }

            if (in_array($shortName, self::EXCLUDED_LOGGER_SHORT_NAMES, true)) {
                continue;
            }

            return true;
        }

        return false;
    }

    /**
     * Type-based receiver gate: `$scope->getType($node->var)` must be a
     * (subtype of) `Illuminate\Database\ConnectionInterface`. PHPStan's
     * `ObjectType::isSuperTypeOf()` handles subtyping cleanly via the
     * fully-static reflection engine — no runtime `is_a()` needed.
     */
    private function receiverIsConnectionInterface(MethodCall $node, Scope $scope): bool
    {
        $receiverType = $scope->getType($node->var);
        $connectionType = new ObjectType(ConnectionInterface::class);

        return $connectionType->isSuperTypeOf($receiverType)->yes();
    }

    /**
     * Honour `// @audit-snapshot-retry-safety: <rationale>` marker on a
     * comment attached to or preceding the transaction call.
     *
     * Empirical finding: PHPStan does NOT propagate a `parent` attribute
     * onto AST nodes (verified against 2.x), so PHPParser's idiomatic
     * `$node->getComments()` returns nothing for a `MethodCall` whose
     * enclosing `Expression` carries the comment. We fall back to the
     * approach used by the cross-territory arch test predecessors: scan
     * the raw source file upward from the call's start line through a
     * contiguous comment/blank block, marker anywhere in the block exempts
     * the call. This mirrors the kendo arch test (PR #1029) verbatim.
     */
    private function hasExemptionMarker(MethodCall $node, Scope $scope): bool
    {
        // Try the idiomatic API first — covers the rare case where comments
        // do attach directly to the MethodCall (e.g. multi-line argument
        // contexts where the parser bound the comment to the inner expr).
        foreach ($node->getComments() as $comment) {
            if (str_contains($comment->getText(), '@audit-snapshot-retry-safety')) {
                return true;
            }
        }

        $file = $scope->getFile();

        if ($file === '' || !is_file($file)) {
            return false;
        }

        $source = @file_get_contents($file);

        if ($source === false) {
            return false;
        }

        $lines = explode("\n", $source);
        $startLine = $node->getStartLine();
        $idx = $startLine - 2; // 0-indexed previous line

        while ($idx >= 0) {
            $line = mb_trim($lines[$idx]);

            if ($line === '') {
                $idx--;

                continue;
            }

            $isCommentLine = str_starts_with($line, '//')
                || str_starts_with($line, '*')
                || str_starts_with($line, '/*');

            if (!$isCommentLine) {
                return false;
            }

            if (str_contains($line, '@audit-snapshot-retry-safety')) {
                return true;
            }

            $idx--;
        }

        return false;
    }

    /**
     * The closure's first non-trivial (non-Nop) statement must satisfy one
     * of the three shapes (a/b/c). An `if` block as first statement is an
     * accepted escape hatch when any inner Expression statement satisfies a
     * shape.
     */
    private function closureFirstStatementIsCompliant(MethodCall $node): bool
    {
        $firstArg = $node->args[0] ?? null;

        if (!$firstArg instanceof Arg || !$firstArg->value instanceof Closure) {
            return false;
        }

        $closure = $firstArg->value;
        $firstStmt = null;

        foreach ($closure->stmts as $stmt) {
            if ($stmt instanceof Nop) {
                continue;
            }

            $firstStmt = $stmt;

            break;
        }

        if ($firstStmt === null) {
            return false;
        }

        if ($this->statementMatchesShape($firstStmt)) {
            return true;
        }

        if ($firstStmt instanceof If_) {
            return $this->ifBlockContainsCompliantExpression($firstStmt);
        }

        return false;
    }

    private function statementMatchesShape(Node $stmt): bool
    {
        if (!$stmt instanceof Expression) {
            return false;
        }

        $expr = $stmt->expr;

        // Shape (a): $variable->refresh() OR $this->property->refresh()
        if (
            $expr instanceof MethodCall
            && $expr->name instanceof Identifier
            && $expr->name->toString() === 'refresh'
            && ($expr->var instanceof Variable || $expr->var instanceof PropertyFetch)
        ) {
            return true;
        }

        // Shape (c): $model = new SomeClass(...)
        if ($expr instanceof Assign && $expr->expr instanceof New_) {
            return true;
        }

        // Shape (c): $model = $this->property->newInstance()
        if (
            $expr instanceof Assign
            && $expr->expr instanceof MethodCall
            && $expr->expr->name instanceof Identifier
            && $expr->expr->name->toString() === 'newInstance'
            && $expr->expr->var instanceof PropertyFetch
        ) {
            return true;
        }

        // Shape (b): $model = ...->newQuery()->findOrFail(...) / first* / fresh()
        if ($expr instanceof Assign && $expr->expr instanceof MethodCall) {
            $call = $expr->expr;

            if (
                !$call->name instanceof Identifier
                || !in_array($call->name->toString(), self::FRESH_TERMINALS, true)
            ) {
                return false;
            }

            // ->fresh() called on any expression is sufficient — Eloquent's
            // ->fresh() always returns a freshly-fetched copy from DB.
            if ($call->name->toString() === 'fresh') {
                return true;
            }

            // For find* / first*: walk down the chain looking for
            // ->newQuery() rooted at $this->property.
            $current = $call->var;

            while ($current instanceof MethodCall) {
                if (
                    $current->name instanceof Identifier
                    && $current->name->toString() === 'newQuery'
                    && $current->var instanceof PropertyFetch
                ) {
                    return true;
                }

                $current = $current->var;
            }
        }

        return false;
    }

    private function ifBlockContainsCompliantExpression(If_ $node): bool
    {
        $found = false;

        $this->walkNodes($node->stmts, function(Node $inner) use (&$found): void {
            if ($found) {
                return;
            }

            if ($this->statementMatchesShape($inner)) {
                $found = true;
            }
        });

        return $found;
    }

    /**
     * Recursively walk a list of nodes, invoking `$callback` on each one.
     * Mirrors `EnforceActionTransactionsRule::walkNodes()` for parity.
     *
     * @param array<int|string, Node|null> $nodes
     */
    private function walkNodes(array $nodes, callable $callback): void
    {
        foreach ($nodes as $node) {
            if (!$node instanceof Node) {
                continue;
            }

            $callback($node);

            foreach ($node->getSubNodeNames() as $name) {
                $subNode = $node->{$name};

                if ($subNode instanceof Node) {
                    $this->walkNodes([$subNode], $callback);
                } elseif (is_array($subNode)) {
                    $this->walkNodes(
                        array_filter($subNode, static fn(mixed $item): bool => $item instanceof Node),
                        $callback,
                    );
                }
            }
        }
    }
}
