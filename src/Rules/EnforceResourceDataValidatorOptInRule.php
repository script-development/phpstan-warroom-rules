<?php

declare(strict_types = 1);

namespace ScriptDevelopment\PhpstanWarroomRules\Rules;

use PhpParser\Node;
use PhpParser\Node\Expr\Array_;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Identifier;
use PhpParser\Node\Stmt\Class_;
use PhpParser\Node\Stmt\ClassConst;
use PhpParser\Node\Stmt\ClassMethod;
use PHPStan\Analyser\Scope;
use PHPStan\Node\InClassNode;
use PHPStan\Reflection\ClassReflection;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;

use function array_filter;
use function implode;
use function in_array;
use function is_array;
use function sprintf;

/**
 * Enforces ADR-0009 §EAGER_LOAD validator opt-in: a `ResourceData` subclass
 * declaring a non-empty `EAGER_LOAD_COUNT` or `EAGER_LOAD_SUM` constant must
 * call `validateRelationsLoaded()` somewhere in its method bodies. Without
 * the call, missing eager-load aggregates fail open as `0` / `null` rather
 * than throwing — silently re-introducing the silent-zero bug closed by
 * kendo PR #1079 (KD-0494).
 *
 * Doctrine source: ADR-0009 §EAGER_LOAD validator opt-in. Codified after
 * kendo PR #1084 (Armorer, merged 2026-05-07 at db20ea9cf) added a Pest
 * arch test of this shape; war-room enforcement queue #55 promoted the
 * Level-1 territory-local arch test to a Level-2 cross-territory PHPStan
 * rule on 2026-05-07.
 *
 * Scope: classes whose ancestor chain includes the configured base FQCN
 * (default: `App\Http\Resources\ResourceData`). Inheritance is matched
 * via PHPStan reflection — short-name collisions in unrelated namespaces
 * do not fire.
 *
 * Detection (all three must hold):
 *   1. Class transitively extends the configured base class.
 *   2. Class declares at least one of `EAGER_LOAD_COUNT` / `EAGER_LOAD_SUM`
 *      as an own constant with a non-empty array value (empty array `[]`
 *      is a no-op and does not fire).
 *   3. Class body does NOT contain a method-call or static-call with name
 *      `validateRelationsLoaded` anywhere in any method (recursive walk).
 *
 * Compliant call shapes (any one suffices):
 *   - `self::validateRelationsLoaded($model);`
 *   - `static::validateRelationsLoaded($model);`
 *   - `$this->validateRelationsLoaded($model);` (the base method is
 *      `protected static`; the instance form is also accepted for
 *      liberal compatibility with the source-of-truth Pest arch test).
 *
 * Implementation note: registers on `InClassNode` rather than `Class_` to
 * guarantee `$scope->getClassReflection()` is available — at the bare
 * `Class_` node, the scope has not yet entered the class so reflection
 * may be null. The AST walk is local; we only need to confirm presence
 * anywhere in the class body, not type-resolve receivers.
 *
 * @implements Rule<InClassNode>
 */
final class EnforceResourceDataValidatorOptInRule implements Rule
{
    private const array TARGET_CONSTANTS = [
        'EAGER_LOAD_COUNT',
        'EAGER_LOAD_SUM',
    ];

    private const string VALIDATOR_METHOD_NAME = 'validateRelationsLoaded';

    public function __construct(
        private string $resourceDataBaseClass = 'App\Http\Resources\ResourceData',
    ) {}

    public function getNodeType(): string
    {
        return InClassNode::class;
    }

    public function processNode(Node $node, Scope $scope): array
    {
        $classNode = $node->getOriginalNode();

        if (!$classNode instanceof Class_) {
            return [];
        }

        if ($classNode->isAbstract()) {
            return [];
        }

        $classReflection = $node->getClassReflection();

        if (!$this->extendsResourceDataBase($classReflection)) {
            return [];
        }

        $declaredConstants = $this->declaredAggregateConstants($classNode);

        if ($declaredConstants === []) {
            return [];
        }

        if ($this->classBodyCallsValidator($classNode)) {
            return [];
        }

        return [
            RuleErrorBuilder::message(sprintf(
                '%s declares %s but does not call validateRelationsLoaded() — silent-zero bug risk (ADR-0009 / war-room queue #55 / kendo PR #1084 opt-in invariant).',
                $classReflection->getName(),
                implode(', ', $declaredConstants),
            ))
                ->identifier('enforceResourceDataValidatorOptIn.missingValidatorCall')
                ->line($classNode->getStartLine())
                ->build(),
        ];
    }

    /**
     * Inheritance gate: the class must be a (transitive) subclass of the
     * configured base FQCN. Uses PHPStan reflection — handles intermediate
     * abstract layers and namespace-relative `extends` clauses. Short-name
     * collisions in unrelated namespaces do not match.
     */
    private function extendsResourceDataBase(ClassReflection $classReflection): bool
    {
        if ($classReflection->getName() === $this->resourceDataBaseClass) {
            return false;
        }

        return $classReflection->isSubclassOf($this->resourceDataBaseClass);
    }

    /**
     * Returns short names of TARGET_CONSTANTS declared on this class with a
     * non-empty array value. Inherited constants are skipped — only own
     * declarations on this class create the obligation.
     *
     * @return list<string>
     */
    private function declaredAggregateConstants(Class_ $node): array
    {
        $declared = [];

        foreach ($node->stmts as $stmt) {
            if (!$stmt instanceof ClassConst) {
                continue;
            }

            foreach ($stmt->consts as $const) {
                $name = $const->name->toString();

                if (!in_array($name, self::TARGET_CONSTANTS, true)) {
                    continue;
                }

                if (!$const->value instanceof Array_) {
                    continue;
                }

                if ($const->value->items === []) {
                    continue;
                }

                $declared[] = $name;
            }
        }

        return $declared;
    }

    /**
     * Walks every method in the class body looking for a method-call or
     * static-call to `validateRelationsLoaded`. Only the call's *name* is
     * checked — any receiver shape (`self::`, `static::`, `$this->`,
     * `parent::`, `Foo::`) suffices, mirroring the source-of-truth kendo
     * arch test's permissive matcher.
     */
    private function classBodyCallsValidator(Class_ $node): bool
    {
        $found = false;

        foreach ($node->stmts as $stmt) {
            if (!$stmt instanceof ClassMethod) {
                continue;
            }

            if ($stmt->stmts === null) {
                continue;
            }

            $this->walkNodes($stmt->stmts, function(Node $inner) use (&$found): void {
                if ($found) {
                    return;
                }

                if (
                    $inner instanceof MethodCall
                    && $inner->name instanceof Identifier
                    && $inner->name->toString() === self::VALIDATOR_METHOD_NAME
                ) {
                    $found = true;

                    return;
                }

                if (
                    $inner instanceof StaticCall
                    && $inner->name instanceof Identifier
                    && $inner->name->toString() === self::VALIDATOR_METHOD_NAME
                ) {
                    $found = true;
                }
            });

            if ($found) {
                return true;
            }
        }

        return false;
    }

    /**
     * Recursively walk a list of nodes, invoking `$callback` on each one.
     * Mirrors `EnforceActionTransactionsRule::walkNodes()` /
     * `EnforceAuditSnapshotOnRetryRule::walkNodes()` for parity.
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
