<?php

declare(strict_types = 1);

namespace ScriptDevelopment\PhpstanWarroomRules\Rules;

use Illuminate\Foundation\Http\FormRequest;
use PhpParser\Node;
use PhpParser\Node\Stmt\Class_;
use PHPStan\Analyser\Scope;
use PHPStan\Node\InClassNode;
use PHPStan\Reflection\ClassReflection;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;

use function in_array;
use function sprintf;

/**
 * Enforces ADR-0012 §FormRequest → DTO Flow: every concrete `FormRequest`
 * subclass must define (or inherit) a `toDto()` method — or its ADR-0020
 * bulk-list sibling `toDtos()` (one request → `list<…Data>`) — so validated
 * input crosses the HTTP boundary as a typed DTO, never as a raw validated
 * array. Without either method, controllers hand `$request->validated()`
 * arrays to Actions — untyped, key-renameable, and invisible to static
 * analysis.
 *
 * Doctrine source: ADR-0012 (FormRequest → DTO Flow). Promoted from
 * entreezuil's reflection-based Pest arch test
 * (`tests/Arch/FormRequestsTest.php`, "form requests with mutation actions
 * define toDto method") — the second instance of the "arch test detects
 * misuse but not omission" enforcement shape under war-room enforcement
 * queue #55, dispositioned for Phase-2 promotion by the Commander on
 * 2026-05-07. Sister of `EnforceResourceDataValidatorOptInRule` (instance 3,
 * PR #20).
 *
 * Scope: classes whose ancestor chain includes the configured base FQCN
 * (default: `Illuminate\Foundation\Http\FormRequest`). Inheritance is
 * matched via PHPStan reflection — short-name collisions in unrelated
 * namespaces do not fire. Abstract classes are skipped (the per-territory
 * `BaseFormRequest` shape is an intermediate layer, not a mutation request).
 *
 * Detection (all three must hold):
 *   1. Class transitively extends the configured base class.
 *   2. Class is concrete (abstract intermediates are exempt).
 *   3. Class neither declares nor inherits a `toDto()` OR `toDtos()`
 *      method — own declarations, parent-class declarations, and
 *      trait-provided methods of EITHER name all satisfy the contract
 *      (mirroring the source-of-truth Pest test's `method_exists()` matcher,
 *      which already accepts both). `toDtos()` is the ADR-0020 bulk-list
 *      convention (bulk-reorder requests convert to `list<…Data>`); the
 *      singular check alone false-positived on every such request.
 *
 * Legitimately DTO-less requests (entreezuil precedent: `LoginRequest`,
 * whose auth flow calls `AuthManager::attempt()` directly) are suppressed
 * one of two ways, both consumer-config-driven — never by name inside the
 * rule, per the package convention:
 *   1. Per-file `phpstan.neon` `ignoreErrors` keyed on the identifier +
 *      path (brittle to file moves).
 *   2. The `formRequestToDtoExemptClasses` PHPStan parameter — a list of
 *      fully-qualified class names to skip (matched by exact FQCN). This is
 *      the class-keyed alternative to `ignoreErrors`, intended for porting a
 *      retiring local arch test's FQCN exemption list into package config
 *      1:1. A consumer-supplied FQCN list is *config*, not a rule-body
 *      literal — no class name is ever hardcoded in this rule, so the
 *      "never by name inside the rule" convention is preserved.
 *
 * Implementation note: the constructor default uses `FormRequest::class`
 * (compile-time constant, never autoloads) instead of an FQCN string
 * literal. Pint's class_keyword fixer calls class_exists() on string
 * literals that look like class names, and the Pint phar bundles a real
 * `Illuminate\Foundation\Http\FormRequest` whose ValidatesWhenResolvedTrait
 * dependency is NOT bundled — a bare FQCN string literal anywhere in this
 * package's PHP source makes `composer format` fatal with "Trait not
 * found". The `use` import is alias-only; consumers analysing non-Laravel
 * trees are unaffected because `::class` resolution requires no autoload.
 *
 * @implements Rule<InClassNode>
 */
final class EnforceFormRequestToDtoRule implements Rule
{
    /**
     * The DTO-handoff contract is satisfied by either the singular `toDto()`
     * (one request → one typed DTO) or the plural `toDtos()` (ADR-0020
     * bulk-list pattern — one request → `list<…Data>`, e.g. a bulk-reorder
     * request). A class declaring or inheriting EITHER satisfies the
     * contract; only a class with NEITHER is flagged. Mirrors the
     * source-of-truth Pest test (`FormRequestsTest`) which already accepts
     * both method names.
     *
     * @var list<string>
     */
    private const array DTO_METHOD_NAMES = ['toDto', 'toDtos'];

    /**
     * @param list<string> $exemptClasses fully-qualified class names to skip
     *                                    (exact-FQCN match); the class-keyed
     *                                    alternative to `ignoreErrors`,
     *                                    supplied only from consumer config
     */
    public function __construct(
        private string $formRequestBaseClass = FormRequest::class,
        private array $exemptClasses = [],
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

        if (!$this->extendsFormRequestBase($classReflection)) {
            return [];
        }

        foreach (self::DTO_METHOD_NAMES as $method) {
            if ($classReflection->hasNativeMethod($method)) {
                return [];
            }
        }

        // Class-keyed consumer exemption: exact-FQCN match against the
        // configured list. Predictable (no short-name collisions), and it
        // ports a retiring local arch test's exempt-class list 1:1. Names
        // live in consumer config, never in this rule body.
        if (in_array($classReflection->getName(), $this->exemptClasses, true)) {
            return [];
        }

        return [
            RuleErrorBuilder::message(sprintf(
                '%s extends FormRequest but does not define a toDto()/toDtos() method — raw validated-array handoff risk (ADR-0012 / war-room queue #55 / entreezuil FormRequestsTest opt-in invariant).',
                $classReflection->getName(),
            ))
                ->identifier('enforceFormRequestToDto.missingToDtoMethod')
                ->line($classNode->getStartLine())
                ->build(),
        ];
    }

    /**
     * Inheritance gate: the class must be a (transitive) subclass of the
     * configured base FQCN. Uses PHPStan reflection — handles intermediate
     * abstract layers and namespace-relative `extends` clauses. Short-name
     * collisions in unrelated namespaces do not match, and the base class
     * itself is not a subclass of itself.
     */
    private function extendsFormRequestBase(ClassReflection $classReflection): bool
    {
        return $classReflection->isSubclassOf($this->formRequestBaseClass);
    }
}
