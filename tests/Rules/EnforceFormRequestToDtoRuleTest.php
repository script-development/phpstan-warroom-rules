<?php

declare(strict_types = 1);

namespace ScriptDevelopment\PhpstanWarroomRules\Tests\Rules;

use PHPStan\Rules\Rule;
use PHPStan\Testing\RuleTestCase;
use ScriptDevelopment\PhpstanWarroomRules\Rules\EnforceFormRequestToDtoRule;

/**
 * @extends RuleTestCase<EnforceFormRequestToDtoRule>
 */
final class EnforceFormRequestToDtoRuleTest extends RuleTestCase
{
    /**
     * Override hook: when set, `getRule()` returns this instance instead of
     * the default. Lets a single test reconfigure the base FQCN parameter.
     */
    private ?Rule $ruleOverride = null;

    public function testViolatorWithoutToDtoIsFlagged(): void
    {
        $this->analyse(
            [
                __DIR__ . '/../Fixtures/FormRequestToDto/_stubs.php',
                __DIR__ . '/../Fixtures/FormRequestToDto/ViolatorRequest.php',
            ],
            [
                [
                    'App\Http\Requests\ViolatorRequest extends FormRequest but does not define a toDto() method — raw validated-array handoff risk (ADR-0012 / war-room queue #55 / entreezuil FormRequestsTest opt-in invariant).',
                    9,
                ],
            ],
        );
    }

    public function testCompliantOwnToDtoIsNotFlagged(): void
    {
        $this->analyse(
            [
                __DIR__ . '/../Fixtures/FormRequestToDto/_stubs.php',
                __DIR__ . '/../Fixtures/FormRequestToDto/CompliantToDtoRequest.php',
            ],
            [],
        );
    }

    public function testCompliantInheritedToDtoIsNotFlagged(): void
    {
        // The abstract intermediate declares toDto(); the concrete leaf
        // inherits it. Neither fires — abstract classes are exempt, and
        // inherited declarations satisfy the contract (mirroring the
        // source-of-truth Pest test's method_exists() matcher).
        $this->analyse(
            [
                __DIR__ . '/../Fixtures/FormRequestToDto/_stubs.php',
                __DIR__ . '/../Fixtures/FormRequestToDto/CompliantInheritedToDtoRequest.php',
            ],
            [],
        );
    }

    public function testAbstractBaseWithoutToDtoIsNotFlagged(): void
    {
        // The per-territory `BaseFormRequest` shape: abstract, extends the
        // framework base, declares no toDto(). Abstract classes are exempt.
        $this->analyse(
            [
                __DIR__ . '/../Fixtures/FormRequestToDto/_stubs.php',
                __DIR__ . '/../Fixtures/FormRequestToDto/AbstractBaseRequest.php',
            ],
            [],
        );
    }

    public function testUnrelatedShortNameCollisionIsNotFlaggedUnderDefaultBase(): void
    {
        // The class extends `App\Unrelated\FormRequest`, not the configured
        // default base `Illuminate\Foundation\Http\FormRequest`. Short-name
        // collision must NOT match — verifying the rule's FQCN-based
        // inheritance gate (vs short-name string match).
        $this->analyse(
            [__DIR__ . '/../Fixtures/FormRequestToDto/UnrelatedShortNameCollision.php'],
            [],
        );
    }

    public function testCustomBaseClassParameterMatchesAlternativeFqcn(): void
    {
        // Re-run the same fixture with the parameter overridden to point at
        // the alternative base FQCN — must now fire. Proves the
        // `formRequestBaseClass` parameter is honored end-to-end.
        $this->ruleOverride = new EnforceFormRequestToDtoRule('App\Unrelated\FormRequest');

        $this->analyse(
            [__DIR__ . '/../Fixtures/FormRequestToDto/UnrelatedShortNameCollision.php'],
            [
                [
                    'App\Unrelated\UnrelatedShortNameCollision extends FormRequest but does not define a toDto() method — raw validated-array handoff risk (ADR-0012 / war-room queue #55 / entreezuil FormRequestsTest opt-in invariant).',
                    12,
                ],
            ],
        );
    }

    protected function getRule(): Rule
    {
        return $this->ruleOverride ?? new EnforceFormRequestToDtoRule;
    }
}
