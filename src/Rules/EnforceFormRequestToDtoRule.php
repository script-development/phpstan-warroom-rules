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

use function sprintf;

/**
 * Enforces ADR-0012 §FormRequest → DTO Flow: every concrete `FormRequest`
 * subclass must define (or inherit) a `toDto()` method so validated input
 * crosses the HTTP boundary as a typed DTO, never as a raw validated array.
 * Without the method, controllers hand `$request->validated()` arrays to
 * Actions — untyped, key-renameable, and invisible to static analysis.
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
 *   3. Class neither declares nor inherits a `toDto()` method — own
 *      declarations, parent-class declarations, and trait-provided methods
 *      all satisfy the contract (mirroring the source-of-truth Pest test's
 *      `method_exists()` matcher).
 *
 * Legitimately DTO-less requests (entreezuil precedent: `LoginRequest`,
 * whose auth flow calls `AuthManager::attempt()` directly) are suppressed
 * per consumer `phpstan.neon` `ignoreErrors` keyed on the identifier —
 * never by name inside the rule, per the package convention.
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
    private const string DTO_METHOD_NAME = 'toDto';

    public function __construct(
        private string $formRequestBaseClass = FormRequest::class,
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

        if ($classReflection->hasNativeMethod(self::DTO_METHOD_NAME)) {
            return [];
        }

        return [
            RuleErrorBuilder::message(sprintf(
                '%s extends FormRequest but does not define a toDto() method — raw validated-array handoff risk (ADR-0012 / war-room queue #55 / entreezuil FormRequestsTest opt-in invariant).',
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
