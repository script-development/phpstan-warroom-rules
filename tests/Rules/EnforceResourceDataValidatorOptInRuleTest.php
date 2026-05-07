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
        $this->ruleOverride = new EnforceResourceDataValidatorOptInRule('App\Unrelated\ResourceData');

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

    protected function getRule(): Rule
    {
        return $this->ruleOverride ?? new EnforceResourceDataValidatorOptInRule;
    }
}
