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
                    'App\Http\Requests\ViolatorRequest extends FormRequest but does not define a toDto()/toDtos() method — raw validated-array handoff risk (ADR-0012 / war-room queue #55 / entreezuil FormRequestsTest opt-in invariant).',
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

    public function testCompliantToDtosBulkListIsNotFlagged(): void
    {
        // ADR-0020 bulk-list convention: the request defines only the plural
        // `toDtos(): array` (one request → list<…Data>), no singular toDto().
        // The rule must NOT flag it — accepting either method name is the fix
        // for the false positive against every bulk-reorder request, and it
        // matches the source-of-truth FormRequestsTest arch test, which
        // already accepts toDtos().
        $this->analyse(
            [
                __DIR__ . '/../Fixtures/FormRequestToDto/_stubs.php',
                __DIR__ . '/../Fixtures/FormRequestToDto/CompliantToDtosRequest.php',
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

    public function testTraitProvidedToDtoIsNotFlagged(): void
    {
        // A concrete request whose toDto() arrives via a trait. The rule
        // routes through PHPStan's hasNativeMethod(), which flattens
        // trait-composed methods — pins the trait leg of the contract that
        // the docblock, README, and CHANGELOG all promise but no fixture
        // previously exercised.
        $this->analyse(
            [
                __DIR__ . '/../Fixtures/FormRequestToDto/_stubs.php',
                __DIR__ . '/../Fixtures/FormRequestToDto/TraitProvidedToDtoRequest.php',
            ],
            [],
        );
    }

    public function testConcreteLeafExtendingAbstractBaseWithoutToDtoIsFlagged(): void
    {
        // The inverse of testCompliantInheritedToDtoIsNotFlagged: a concrete
        // leaf extends the abstract intermediate `AbstractBaseRequest`, which
        // declares no toDto() anywhere in the chain. Transitive-violation
        // detection must fire at the leaf — proving the ancestor traversal
        // catches omission through an intermediate abstract layer, not only
        // direct framework-base extension.
        $this->analyse(
            [
                __DIR__ . '/../Fixtures/FormRequestToDto/_stubs.php',
                __DIR__ . '/../Fixtures/FormRequestToDto/AbstractBaseRequest.php',
                __DIR__ . '/../Fixtures/FormRequestToDto/TransitiveViolatorRequest.php',
            ],
            [
                [
                    'App\Http\Requests\TransitiveViolatorRequest extends FormRequest but does not define a toDto()/toDtos() method — raw validated-array handoff risk (ADR-0012 / war-room queue #55 / entreezuil FormRequestsTest opt-in invariant).',
                    13,
                ],
            ],
        );
    }

    public function testRuleResolvesFromExtensionNeonAndFires(): void
    {
        // End-to-end pin on the extension.neon registration path consumers
        // actually use: resolve the rule from the PHPStan container so the
        // shipped `formRequestBaseClass` default and the `%formRequestBaseClass%`
        // argument wiring are exercised — NOT the PHP constructor default.
        // A NEON quoting regression in the shipped default (e.g. the
        // double-backslash single-quoted form) silently no-ops the rule while
        // every direct-instantiation test stays green; this is the only gate
        // that catches it.
        $this->ruleOverride = self::getContainer()->getByType(EnforceFormRequestToDtoRule::class);

        $this->analyse(
            [
                __DIR__ . '/../Fixtures/FormRequestToDto/_stubs.php',
                __DIR__ . '/../Fixtures/FormRequestToDto/ViolatorRequest.php',
            ],
            [
                [
                    'App\Http\Requests\ViolatorRequest extends FormRequest but does not define a toDto()/toDtos() method — raw validated-array handoff risk (ADR-0012 / war-room queue #55 / entreezuil FormRequestsTest opt-in invariant).',
                    9,
                ],
            ],
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
        $this->ruleOverride = new EnforceFormRequestToDtoRule(
            $this->createReflectionProvider(),
            'App\Unrelated\FormRequest',
        );

        $this->analyse(
            [__DIR__ . '/../Fixtures/FormRequestToDto/UnrelatedShortNameCollision.php'],
            [
                [
                    'App\Unrelated\UnrelatedShortNameCollision extends FormRequest but does not define a toDto()/toDtos() method — raw validated-array handoff risk (ADR-0012 / war-room queue #55 / entreezuil FormRequestsTest opt-in invariant).',
                    12,
                ],
            ],
        );
    }

    public function testViolatorWithEmptyExemptListIsFlagged(): void
    {
        // Regression: the new exemptClasses param defaults to empty; default
        // behaviour must be unchanged (violator still fires).
        $this->ruleOverride = new EnforceFormRequestToDtoRule(
            $this->createReflectionProvider(),
            exemptClasses: [],
        );

        $this->analyse(
            [
                __DIR__ . '/../Fixtures/FormRequestToDto/_stubs.php',
                __DIR__ . '/../Fixtures/FormRequestToDto/ViolatorRequest.php',
            ],
            [
                [
                    'App\Http\Requests\ViolatorRequest extends FormRequest but does not define a toDto()/toDtos() method — raw validated-array handoff risk (ADR-0012 / war-room queue #55 / entreezuil FormRequestsTest opt-in invariant).',
                    9,
                ],
            ],
        );
    }

    public function testExemptClassByFqcnIsNotFlagged(): void
    {
        // The violator's exact FQCN is in the exempt list — the class-keyed
        // consumer exemption path. No error.
        $this->ruleOverride = new EnforceFormRequestToDtoRule(
            $this->createReflectionProvider(),
            exemptClasses: ['App\Http\Requests\ViolatorRequest'],
        );

        $this->analyse(
            [
                __DIR__ . '/../Fixtures/FormRequestToDto/_stubs.php',
                __DIR__ . '/../Fixtures/FormRequestToDto/ViolatorRequest.php',
            ],
            [],
        );
    }

    public function testExemptionIsPreciseNotGlobalOffSwitch(): void
    {
        // Exempting one FQCN must not silence a DIFFERENT non-exempt violator
        // analysed in the same run — the exemption is precise, not a global
        // off-switch.
        $this->ruleOverride = new EnforceFormRequestToDtoRule(
            $this->createReflectionProvider(),
            exemptClasses: ['App\Http\Requests\ViolatorRequest'],
        );

        $this->analyse(
            [
                __DIR__ . '/../Fixtures/FormRequestToDto/_stubs.php',
                __DIR__ . '/../Fixtures/FormRequestToDto/ViolatorRequest.php',
                __DIR__ . '/../Fixtures/FormRequestToDto/SecondViolatorRequest.php',
            ],
            [
                [
                    'App\Http\Requests\SecondViolatorRequest extends FormRequest but does not define a toDto()/toDtos() method — raw validated-array handoff risk (ADR-0012 / war-room queue #55 / entreezuil FormRequestsTest opt-in invariant).',
                    12,
                ],
            ],
        );
    }

    public function testExemptMatchIsExactFqcnNotShortNameOrOtherNamespace(): void
    {
        // The match is exact-FQCN: neither the bare short name nor an
        // unrelated-namespace class of the same short name exempts the real
        // `App\Http\Requests\ViolatorRequest` — it must still fire.
        $this->ruleOverride = new EnforceFormRequestToDtoRule(
            $this->createReflectionProvider(),
            exemptClasses: ['ViolatorRequest', 'App\Other\ViolatorRequest'],
        );

        $this->analyse(
            [
                __DIR__ . '/../Fixtures/FormRequestToDto/_stubs.php',
                __DIR__ . '/../Fixtures/FormRequestToDto/ViolatorRequest.php',
            ],
            [
                [
                    'App\Http\Requests\ViolatorRequest extends FormRequest but does not define a toDto()/toDtos() method — raw validated-array handoff risk (ADR-0012 / war-room queue #55 / entreezuil FormRequestsTest opt-in invariant).',
                    9,
                ],
            ],
        );
    }

    public function testBaseClassAbsentFromTreeIsNoOp(): void
    {
        // The unknown-base-class no-op (queue #112). The rule is configured with
        // a base FQCN that is absent from every analysed tree. The violator
        // fixture structurally extends the (stubbed) real FormRequest base, but
        // because the CONFIGURED base `App\Absent\NonExistentFormRequestBase`
        // does not exist, the migrated `isSubclassOfClass()` resolution path
        // takes the `hasClass()`-false branch and silently no-ops — reproducing
        // the deprecated `isSubclassOf(string)` false return exactly (its body:
        // `if (!hasClass($fqcn)) return false;`). This is the load-bearing
        // "consumers analysing non-Laravel trees are unaffected" guarantee: a
        // tree lacking the configured base never fires the rule.
        //
        // A bogus-base FQCN is used rather than omitting the framework stub:
        // RuleTestCase analyses share ONE PHP process, so a stub required by an
        // earlier test leaks `Illuminate\Foundation\Http\FormRequest` into
        // runtime reflection for every later test, making "omit the stub"
        // order-dependent and unsound. An always-absent FQCN is deterministic.
        $this->ruleOverride = new EnforceFormRequestToDtoRule(
            $this->createReflectionProvider(),
            'App\Absent\NonExistentFormRequestBase',
        );

        $this->analyse(
            [
                __DIR__ . '/../Fixtures/FormRequestToDto/_stubs.php',
                __DIR__ . '/../Fixtures/FormRequestToDto/ViolatorRequest.php',
            ],
            [],
        );
    }

    /**
     * Load the shipped extension.neon so testRuleResolvesFromExtensionNeonAndFires
     * can pull the rule out of the container with its NEON-configured
     * `formRequestBaseClass` parameter applied.
     *
     * @return array<int, string>
     */
    public static function getAdditionalConfigFiles(): array
    {
        return [
            __DIR__ . '/../../extension.neon',
        ];
    }

    protected function getRule(): Rule
    {
        return $this->ruleOverride ?? new EnforceFormRequestToDtoRule($this->createReflectionProvider());
    }
}
