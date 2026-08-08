<?php

declare(strict_types = 1);

namespace ScriptDevelopment\PhpstanWarroomRules\Rules;

use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\Array_;
use PhpParser\Node\Expr\BinaryOp\Coalesce;
use PhpParser\Node\Expr\ClassConstFetch;
use PhpParser\Node\Expr\ConstFetch;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\NullsafeMethodCall;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Expr\Ternary;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\Scalar\Float_;
use PhpParser\Node\Scalar\Int_;
use PhpParser\Node\Scalar\String_;
use PHPStan\Analyser\Scope;
use PHPStan\Reflection\ParametersAcceptor;
use PHPStan\Reflection\ReflectionProvider;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;
use PHPStan\Type\MixedType;
use PHPStan\Type\NeverType;
use PHPStan\Type\Type;
use PHPStan\Type\TypeCombinator;
use PHPStan\Type\UnionType;

use function mb_rtrim;
use function sprintf;
use function str_starts_with;
use function var_export;

/**
 * Forbids a SENTINEL fallback (`?? ''`, `?? 0`, `?: 'unknown'`) on the result of
 * a NARROWING HELPER — a boundary helper that takes `mixed` external data and
 * returns a nullable scalar, where `null` is the helper's way of saying "this
 * input was unreadable".
 *
 * A sentinel fallback converts that failure signal into a plausible-looking
 * value. The value then gets persisted, compared, or used to unlock a branch,
 * and the original unreadable input is unrecoverable. Confirmed damage from
 * tc-api PR #360, all three from this one shape:
 *
 *   - `Carbon::parse(text($leaf) ?? null)` — a missing node parsed as `now()`,
 *     writing today's date as a person's date of birth.
 *   - `?? ''` seeding `['']` — a one-element array holding an empty string reads
 *     as non-empty, which unlocked a `forceDelete()` wipe.
 *   - a gender written to the database as an empty `SET` string.
 *
 * The remediation is always one of: skip the write, fail closed (throw), or
 * handle `null` explicitly. `?? null` is deliberately NOT flagged — it preserves
 * the failure signal for the caller downstream.
 *
 * Doctrine source: war-room §Architectural Principles — Explicit over implicit
 * (a swallowed parse failure is the implicit path); fail-closed data-integrity
 * posture for the boundary/import surface.
 *
 * Detection is SHAPE-KEYED, never a helper-name list — a territory's helper
 * inventory changes, the shape does not. A left-hand call fires only when its
 * resolved reflection satisfies ALL of:
 *
 *   1. The declaring class (method / static call) or the function FQN (plain
 *      function) sits under a configured namespace prefix
 *      (`narrowingHelperNamespacePrefixes`, default `App\`). This is the
 *      non-vendor gate: `filter_var(...) ?? ''` and any framework call are
 *      structurally out of scope, because a vendor helper's null contract is not
 *      ours to reason about.
 *   2. At least one parameter is typed `mixed` — explicitly or by being untyped
 *      (both resolve to `MixedType`). That is the boundary tell: the helper is
 *      handed unvalidated external data.
 *   3. The return type is a nullable scalar or a nullable union of scalars
 *      (`?string`, `?int`, `?float`, `?bool`, `string|int|null`). Checked via the
 *      PHPStan Type API (`TypeCombinator::containsNull()` + a per-member scalar
 *      probe on the non-null remainder), never by string-comparing type names.
 *
 * The right-hand side must be a LITERAL sentinel — a scalar literal (`''`,
 * `0`, `0.0`, `'unknown'`), `true` / `false`, a class constant, or an empty
 * array. A non-literal right side (a variable, a method call, a coalescing
 * chain) is left alone on purpose: the fallback may itself be a legitimate
 * nullable, and flagging it is the false-positive-rich half of the shape
 * (ADR-0021 posture — false negatives acceptable, false positives are not).
 *
 * `getNodeType()` is `Expr` rather than a narrower node because the two shapes
 * this rule must see — `Coalesce` (a `BinaryOp`) and the short `Ternary` — have
 * no common ancestor below `Expr`. Both are matched by an instanceof guard on
 * the first line, and the cheap AST-only sentinel check runs before any
 * reflection work, so the per-node cost on non-matching expressions is one
 * instanceof.
 *
 * Deliberate misses:
 *
 *   - The long ternary (`$x = text($leaf) !== null ? text($leaf) : ''`) — an
 *     explicit null test IS the "handle null explicitly" remediation; only the
 *     eliding short form is the violation.
 *   - A helper result laundered through a variable
 *     (`$name = text($leaf); $name ?? ''`) — provenance is gone at the coalesce;
 *     closing it needs data-flow tracking, not a wider matcher.
 *
 * @implements Rule<Expr>
 */
final class ForbidSentinelFallbackOnNarrowingHelperRule implements Rule
{
    /**
     * @param list<string> $narrowingHelperNamespacePrefixes namespace prefixes
     *                                                       whose classes /
     *                                                       functions are
     *                                                       first-party enough to
     *                                                       reason about (default
     *                                                       `App\`). A trailing
     *                                                       namespace separator is
     *                                                       optional — `App` and
     *                                                       `App\` behave
     *                                                       identically, and both
     *                                                       match on a namespace
     *                                                       boundary so
     *                                                       `Application\Foo` is
     *                                                       never swept in.
     */
    public function __construct(
        private ReflectionProvider $reflectionProvider,
        private array $narrowingHelperNamespacePrefixes = ['App\\'],
    ) {}

    public function getNodeType(): string
    {
        return Expr::class;
    }

    public function processNode(Node $node, Scope $scope): array
    {
        if ($node instanceof Coalesce) {
            $call = $node->left;
            $fallback = $node->right;
            $operator = '??';
        } elseif ($node instanceof Ternary && $node->if === null) {
            $call = $node->cond;
            $fallback = $node->else;
            $operator = '?:';
        } else {
            return [];
        }

        // AST-only, and cheapest of the two gates — run it before any reflection.
        $sentinel = $this->describeSentinel($fallback);

        if ($sentinel === null) {
            return [];
        }

        $helper = $this->resolveNarrowingHelper($call, $scope);

        if ($helper === null) {
            return [];
        }

        return [
            RuleErrorBuilder::message(sprintf(
                'Narrowing helper %s() returns null for unreadable input; the `%s %s` fallback hides that failure '
                . 'by turning it into a plausible value that gets persisted or unlocks a branch. '
                . 'Skip the write, fail closed, or handle null explicitly (`?? null` preserves the failure signal).',
                $helper,
                $operator,
                $sentinel,
            ))
                ->identifier('forbidSentinelFallbackOnNarrowingHelper.sentinelFallback')
                ->build(),
        ];
    }

    /**
     * Render the fallback expression when it is a literal sentinel, else null.
     * `null` itself is NOT a sentinel — it preserves the failure signal — and a
     * non-empty array literal is out of scope (its emptiness, not its content,
     * is what makes `[]` a plausible-looking value).
     */
    private function describeSentinel(Expr $expr): ?string
    {
        if ($expr instanceof String_ || $expr instanceof Int_ || $expr instanceof Float_) {
            return var_export($expr->value, true);
        }

        if ($expr instanceof ConstFetch) {
            return $expr->name->toLowerString() === 'null' ? null : $expr->name->toString();
        }

        if ($expr instanceof ClassConstFetch && $expr->class instanceof Name && $expr->name instanceof Identifier) {
            return $expr->class->toString() . '::' . $expr->name->toString();
        }

        if ($expr instanceof Array_) {
            return $expr->items === [] ? '[]' : null;
        }

        return null;
    }

    /**
     * Resolve the left-hand call to its reflection and return a display name
     * (`App\Support\LeafReader::text`, `App\Support\text`) when it is a
     * narrowing helper; null otherwise.
     */
    private function resolveNarrowingHelper(Expr $expr, Scope $scope): ?string
    {
        if ($expr instanceof MethodCall || $expr instanceof NullsafeMethodCall) {
            if (!$expr->name instanceof Identifier) {
                return null;
            }

            $methodName = $expr->name->toString();
            $calledOnType = $scope->getType($expr->var);

            if (!$calledOnType->hasMethod($methodName)->yes()) {
                return null;
            }

            $method = $calledOnType->getMethod($methodName, $scope);
            $owner = $method->getDeclaringClass()->getName();

            return $this->isNarrowingHelper($owner, $method->getVariants())
                ? $owner . '::' . $methodName
                : null;
        }

        if ($expr instanceof StaticCall) {
            if (!$expr->name instanceof Identifier || !$expr->class instanceof Name) {
                return null;
            }

            $className = $scope->resolveName($expr->class);

            if (!$this->reflectionProvider->hasClass($className)) {
                return null;
            }

            $classReflection = $this->reflectionProvider->getClass($className);
            $methodName = $expr->name->toString();

            if (!$classReflection->hasMethod($methodName)) {
                return null;
            }

            $method = $classReflection->getMethod($methodName, $scope);
            $owner = $method->getDeclaringClass()->getName();

            return $this->isNarrowingHelper($owner, $method->getVariants())
                ? $owner . '::' . $methodName
                : null;
        }

        if ($expr instanceof FuncCall) {
            if (!$expr->name instanceof Name || !$this->reflectionProvider->hasFunction($expr->name, $scope)) {
                return null;
            }

            $function = $this->reflectionProvider->getFunction($expr->name, $scope);
            $owner = $function->getName();

            return $this->isNarrowingHelper($owner, $function->getVariants()) ? $owner : null;
        }

        return null;
    }

    /**
     * The three-part shape gate: first-party namespace, a `mixed` (or untyped)
     * parameter, and a nullable-scalar return.
     *
     * @param array<int, ParametersAcceptor> $variants
     */
    private function isNarrowingHelper(string $owner, array $variants): bool
    {
        if (!$this->isFirstParty($owner)) {
            return false;
        }

        $variant = $variants[0] ?? null;

        if ($variant === null) {
            return false;
        }

        return $this->hasMixedParameter($variant) && $this->returnsNullableScalar($variant->getReturnType());
    }

    /**
     * Namespace-boundary match — `App` and `App\` both accept `App\Support\Foo`
     * and both reject `Application\Foo`.
     */
    private function isFirstParty(string $owner): bool
    {
        foreach ($this->narrowingHelperNamespacePrefixes as $prefix) {
            if (str_starts_with($owner, mb_rtrim($prefix, '\\') . '\\')) {
                return true;
            }
        }

        return false;
    }

    /**
     * A `mixed` parameter is the boundary tell. An UNTYPED parameter also
     * resolves to `MixedType` (implicit mixed), which is the same contract from
     * the caller's side, so both count.
     */
    private function hasMixedParameter(ParametersAcceptor $variant): bool
    {
        foreach ($variant->getParameters() as $parameter) {
            if ($parameter->getType() instanceof MixedType) {
                return true;
            }
        }

        return false;
    }

    /**
     * True for `?string` / `?int` / `?float` / `?bool` and nullable unions of
     * those. Type-API only — never a string comparison on a type name.
     */
    private function returnsNullableScalar(Type $returnType): bool
    {
        if (!TypeCombinator::containsNull($returnType)) {
            return false;
        }

        $nonNull = TypeCombinator::removeNull($returnType);

        // A `null`-only return leaves NeverType behind. NeverType is the bottom
        // type, so every `is*()` probe answers yes — it must be rejected BEFORE
        // the scalar loop or a `: ?null` helper would match everything.
        if ($nonNull instanceof NeverType) {
            return false;
        }

        $members = $nonNull instanceof UnionType ? $nonNull->getTypes() : [$nonNull];

        foreach ($members as $member) {
            if (!$this->isScalar($member)) {
                return false;
            }
        }

        return true;
    }

    private function isScalar(Type $type): bool
    {
        return $type->isString()->yes()
            || $type->isInteger()->yes()
            || $type->isFloat()->yes()
            || $type->isBoolean()->yes();
    }
}
