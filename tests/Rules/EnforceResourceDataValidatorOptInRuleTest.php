<?php

declare(strict_types = 1);

namespace ScriptDevelopment\PhpstanWarroomRules\Tests\Rules;

use PHPStan\Rules\Rule;
use PHPStan\Testing\RuleTestCase;
use ScriptDevelopment\PhpstanWarroomRules\Rules\EnforceResourceDataValidatorOptInRule;

/**
 * @extends RuleTestCase<EnforceResourceDataValidatorOptInRule>
 */
final class EnforceResourceDataValidatorOptInRuleTest extends RuleTestCase
{
    /**
     * Override hook: when set, `getRule()` returns this instance instead of
     * the default. Lets a single test reconfigure the base FQCN parameter.
     */
    private ?Rule $ruleOverride = null;

    public function testViolatorWithEagerLoadCountIsFlagged(): void
    {
        $this->analyse(
            [
                __DIR__ . '/../Fixtures/ResourceDataValidatorOptIn/_stubs.php',
                __DIR__ . '/../Fixtures/ResourceDataValidatorOptIn/ViolatorResource.php',
            ],
            [
                [
                    'App\Http\Resources\ViolatorResource declares EAGER_LOAD_COUNT but does not call validateRelationsLoaded() — silent-zero bug risk (ADR-0009 / war-room queue #55 / kendo PR #1084 opt-in invariant).',
                    9,
                ],
            ],
        );
    }

    public function testViolatorWithBothConstantsIsFlaggedWithCommaSeparatedNames(): void
    {
        $this->analyse(
            [
                __DIR__ . '/../Fixtures/ResourceDataValidatorOptIn/_stubs.php',
                __DIR__ . '/../Fixtures/ResourceDataValidatorOptIn/ViolatorBothConstantsResource.php',
            ],
            [
                [
                    'App\Http\Resources\ViolatorBothConstantsResource declares EAGER_LOAD_COUNT, EAGER_LOAD_SUM but does not call validateRelationsLoaded() — silent-zero bug risk (ADR-0009 / war-room queue #55 / kendo PR #1084 opt-in invariant).',
                    9,
                ],
            ],
        );
    }

    public function testCompliantSelfCallIsNotFlagged(): void
    {
        $this->analyse(
            [
                __DIR__ . '/../Fixtures/ResourceDataValidatorOptIn/_stubs.php',
                __DIR__ . '/../Fixtures/ResourceDataValidatorOptIn/CompliantSelfCallResource.php',
            ],
            [],
        );
    }

    public function testCompliantStaticCallIsNotFlagged(): void
    {
        $this->analyse(
            [
                __DIR__ . '/../Fixtures/ResourceDataValidatorOptIn/_stubs.php',
                __DIR__ . '/../Fixtures/ResourceDataValidatorOptIn/CompliantStaticCallResource.php',
            ],
            [],
        );
    }

    public function testCompliantInstanceCallIsNotFlagged(): void
    {
        $this->analyse(
            [
                __DIR__ . '/../Fixtures/ResourceDataValidatorOptIn/_stubs.php',
                __DIR__ . '/../Fixtures/ResourceDataValidatorOptIn/CompliantInstanceCallResource.php',
            ],
            [],
        );
    }

    public function testNonTargetResourceWithoutAggregateConstantsIsOutOfScope(): void
    {
        $this->analyse(
            [
                __DIR__ . '/../Fixtures/ResourceDataValidatorOptIn/_stubs.php',
                __DIR__ . '/../Fixtures/ResourceDataValidatorOptIn/NonTargetResource.php',
            ],
            [],
        );
    }

    public function testEmptyConstResourceIsNotFlagged(): void
    {
        $this->analyse(
            [
                __DIR__ . '/../Fixtures/ResourceDataValidatorOptIn/_stubs.php',
                __DIR__ . '/../Fixtures/ResourceDataValidatorOptIn/EmptyConstResource.php',
            ],
            [],
        );
    }

    public function testUnrelatedShortNameCollisionIsNotFlaggedUnderDefaultBase(): void
    {
        // The class extends `App\Unrelated\ResourceData`, not the configured
        // default base `App\Http\Resources\ResourceData`. Short-name collision
        // must NOT match — verifying the rule's FQCN-based inheritance gate
        // (vs short-name string match).
        $this->analyse(
            [__DIR__ . '/../Fixtures/ResourceDataValidatorOptIn/UnrelatedShortNameCollision.php'],
            [],
        );
    }

    public function testCustomBaseClassParameterMatchesAlternativeFqcn(): void
    {
        // Re-run the same fixture with the parameter overridden to point at
        // the alternative base FQCN — must now fire. Proves the
        // `resourceDataBaseClass` parameter is honored end-to-end.
        $this->ruleOverride = new EnforceResourceDataValidatorOptInRule(
            $this->createReflectionProvider(),
            'App\Unrelated\ResourceData',
        );

        $this->analyse(
            [__DIR__ . '/../Fixtures/ResourceDataValidatorOptIn/UnrelatedShortNameCollision.php'],
            [
                [
                    'App\Unrelated\UnrelatedShortNameCollision declares EAGER_LOAD_COUNT but does not call validateRelationsLoaded() — silent-zero bug risk (ADR-0009 / war-room queue #55 / kendo PR #1084 opt-in invariant).',
                    12,
                ],
            ],
        );
    }

    public function testRuleResolvesFromExtensionNeonAndFires(): void
    {
        // End-to-end pin on the extension.neon registration path consumers
        // actually use: resolve the rule from the PHPStan container so the
        // shipped `resourceDataBaseClass` default and the
        // `%resourceDataBaseClass%` argument wiring are exercised — NOT the PHP
        // constructor default. The shipped default carried a NEON
        // double-backslash quoting defect that silently no-op'd this rule for
        // every default consumer since PR #20; this gate catches it.
        $this->ruleOverride = self::getContainer()->getByType(EnforceResourceDataValidatorOptInRule::class);

        $this->analyse(
            [
                __DIR__ . '/../Fixtures/ResourceDataValidatorOptIn/_stubs.php',
                __DIR__ . '/../Fixtures/ResourceDataValidatorOptIn/ViolatorResource.php',
            ],
            [
                [
                    'App\Http\Resources\ViolatorResource declares EAGER_LOAD_COUNT but does not call validateRelationsLoaded() — silent-zero bug risk (ADR-0009 / war-room queue #55 / kendo PR #1084 opt-in invariant).',
                    9,
                ],
            ],
        );
    }

    public function testBaseClassAbsentFromTreeIsNoOp(): void
    {
        // The unknown-base-class no-op (queue #112). The rule is configured with
        // a base FQCN that is absent from every analysed tree. The violator
        // fixture structurally extends the (stubbed) real ResourceData base, but
        // because the CONFIGURED base `App\Absent\NonExistentResourceDataBase`
        // does not exist, the migrated `isSubclassOfClass()` resolution path
        // takes the `hasClass()`-false branch and silently no-ops — reproducing
        // the deprecated `isSubclassOf(string)` false return exactly (its body:
        // `if (!hasClass($fqcn)) return false;`). This is the load-bearing
        // "consumers analysing non-Laravel trees are unaffected" guarantee: a
        // tree lacking the configured base never fires the rule.
        //
        // A bogus-base FQCN is used rather than omitting the stub: RuleTestCase
        // analyses share ONE PHP process, so a stub required by an earlier test
        // leaks `App\Http\Resources\ResourceData` into runtime reflection for
        // every later test, making "omit the stub" order-dependent and unsound.
        // An always-absent FQCN is deterministic.
        $this->ruleOverride = new EnforceResourceDataValidatorOptInRule(
            $this->createReflectionProvider(),
            'App\Absent\NonExistentResourceDataBase',
        );

        $this->analyse(
            [
                __DIR__ . '/../Fixtures/ResourceDataValidatorOptIn/_stubs.php',
                __DIR__ . '/../Fixtures/ResourceDataValidatorOptIn/ViolatorResource.php',
            ],
            [],
        );
    }

    /**
     * Load the shipped extension.neon so testRuleResolvesFromExtensionNeonAndFires
     * can pull the rule out of the container with its NEON-configured
     * `resourceDataBaseClass` parameter applied.
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
        return $this->ruleOverride ?? new EnforceResourceDataValidatorOptInRule($this->createReflectionProvider());
    }
}
