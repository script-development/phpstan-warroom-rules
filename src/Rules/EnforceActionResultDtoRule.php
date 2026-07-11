<?php

declare(strict_types = 1);

namespace ScriptDevelopment\PhpstanWarroomRules\Rules;

use PhpParser\Node;
use PhpParser\Node\ComplexType;
use PhpParser\Node\Identifier;
use PhpParser\Node\IntersectionType;
use PhpParser\Node\NullableType;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\UnionType;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\IdentifierRuleError;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;

use function mb_strrpos;
use function mb_strtolower;
use function mb_substr;
use function sprintf;
use function str_starts_with;

/**
 * Enforces that an Action's `execute()` method does not declare an `array`
 * return. A compound result — more than one value handed back to the caller —
 * is a struct that should be a class (a Result DTO), not a bag of string keys.
 * An `: array` return type is the detectable proxy for a struct that escaped
 * typing: it names no fields, guarantees no keys, and forces every caller to
 * re-derive the shape by reading the Action body. Return a Result DTO instead
 * (`EnableCentralTwoFactorResult`, etc.).
 *
 * Doctrine source: ADR-0020 (Input/Result DTO Split by Usage Direction) +
 * ADR-0011 (Action Class Architecture). Seed: kendo PR #1653 (KD-0220
 * central-user 2FA) — `EnableCentralTwoFactorAction::execute()` returned
 * `array{secret, qr_code}`, a two-field struct that should be a Result DTO
 * ("Mooier om hier een Result DTO terug te sturen").
 *
 * Scope:
 *
 *   1. Namespace gate — containing class FQCN must start with `App\Actions`
 *      (the same hardcoded convention as `EnforceActionTransactionsRule` /
 *      `ForbidDatabaseManagerInActionsRule`, ADR-0021 §Action namespace
 *      assumption; no parameter today — lift to config if a territory ships a
 *      divergent actions namespace).
 *   2. Method gate — only the method named `execute` (Actions have exactly one
 *      public method by doctrine; every other method, incl. private helpers
 *      that legitimately return arrays, is ignored).
 *   3. The DECLARED native return type (`ClassMethod->returnType`) is inspected:
 *      - Bare `array` → error.
 *      - `?array` (`NullableType` wrapping `array`) → error.
 *      - A union / intersection with an `array` member (`array|SomeResultDto`)
 *        → error (a union member is still an escape hatch).
 *      - `iterable` (and its nullable / union forms) → error: it admits arrays,
 *        the same hole in an adjacent spelling.
 *      - Everything else — Result-DTO classes, `void`, models, `Collection`,
 *        scalars, `bool`, or no declared type — passes.
 *
 * Deliberately signature-only. A phpdoc-only `@return array{...}` on an
 * otherwise untyped `execute()` is NOT chased: every consumer territory
 * enforces native return types via its own tooling, so an untyped `execute()`
 * is already a violation of a different contract, and phpdoc-shape resolution
 * buys parser complexity without closing a real evasion path. The boundary is
 * pinned by a fixture so it stays explicit.
 *
 * No `list<T>` carve-out. A `list<string>` return (e.g. recovery codes) is
 * spelled `array` natively and converts to a Result DTO all the same
 * (Commander disposition 2026-07-06): the moment `list<T>` is exempt, someone
 * returns `list<array{...}>` through the gap.
 *
 * Suppression: standard PHPStan inline-ignore mechanism on the rule's
 * identifier `enforceActionResultDto.arrayReturnFromExecute`.
 *
 * @implements Rule<ClassMethod>
 */
final class EnforceActionResultDtoRule implements Rule
{
    public function getNodeType(): string
    {
        return ClassMethod::class;
    }

    public function processNode(Node $node, Scope $scope): array
    {
        if ($node->name->toString() !== 'execute') {
            return [];
        }

        $namespace = $scope->getNamespace();

        if ($namespace === null || !str_starts_with($namespace, 'App\Actions')) {
            return [];
        }

        if (!$this->declaredTypeIsOrContainsArray($node->returnType)) {
            return [];
        }

        return [$this->buildError($node, $scope)];
    }

    /**
     * True when the declared native return type is, wraps, or unions/intersects
     * an `array` or `iterable` — the two native spellings that admit an
     * untyped struct. Class-name return types (`Name`), `void`, scalars, and a
     * null declared type all return false (pass).
     *
     * @param Identifier|Node\Name|ComplexType|null $type
     */
    private function declaredTypeIsOrContainsArray(?Node $type): bool
    {
        if ($type instanceof Identifier) {
            $name = mb_strtolower($type->toString());

            return $name === 'array' || $name === 'iterable';
        }

        if ($type instanceof NullableType) {
            return $this->declaredTypeIsOrContainsArray($type->type);
        }

        if ($type instanceof UnionType || $type instanceof IntersectionType) {
            foreach ($type->types as $inner) {
                if ($this->declaredTypeIsOrContainsArray($inner)) {
                    return true;
                }
            }
        }

        return false;
    }

    private function buildError(ClassMethod $node, Scope $scope): IdentifierRuleError
    {
        return RuleErrorBuilder::message(
            sprintf(
                'Action %s::execute() must not return array — return a Result DTO instead (ADR-0020 Input/Result DTO Split).',
                $this->actionShortName($scope),
            ),
        )
            ->identifier('enforceActionResultDto.arrayReturnFromExecute')
            ->line($node->getStartLine())
            ->build();
    }

    private function actionShortName(Scope $scope): string
    {
        $classReflection = $scope->getClassReflection();

        if ($classReflection === null) {
            return 'Action';
        }

        $fqcn = $classReflection->getName();
        $lastSeparator = mb_strrpos($fqcn, '\\');

        return $lastSeparator === false ? $fqcn : mb_substr($fqcn, $lastSeparator + 1);
    }
}
