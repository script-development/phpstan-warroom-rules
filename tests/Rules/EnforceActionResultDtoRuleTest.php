<?php

declare(strict_types = 1);

namespace ScriptDevelopment\PhpstanWarroomRules\Tests\Rules;

use PHPStan\Rules\Rule;
use PHPStan\Testing\RuleTestCase;
use ScriptDevelopment\PhpstanWarroomRules\Rules\EnforceActionResultDtoRule;

/**
 * @extends RuleTestCase<EnforceActionResultDtoRule>
 */
final class EnforceActionResultDtoRuleTest extends RuleTestCase
{
    /**
     * Override hook: when set, `getRule()` returns this instance instead of the
     * default. Lets the container-resolved test swap in the NEON-wired rule.
     */
    private ?Rule $ruleOverride = null;

    public function testFlagsBareArrayReturn(): void
    {
        $this->analyse(
            [__DIR__ . '/../Fixtures/ActionResultDto/BareArrayReturn.php'],
            [
                [
                    'Action BareArrayReturn::execute() must not return array — return a Result DTO instead (ADR-0020 Input/Result DTO Split).',
                    15,
                ],
            ],
        );
    }

    public function testFlagsNullableArrayReturn(): void
    {
        $this->analyse(
            [__DIR__ . '/../Fixtures/ActionResultDto/NullableArrayReturn.php'],
            [
                [
                    'Action NullableArrayReturn::execute() must not return array — return a Result DTO instead (ADR-0020 Input/Result DTO Split).',
                    10,
                ],
            ],
        );
    }

    public function testFlagsUnionWithArrayMember(): void
    {
        $this->analyse(
            [
                __DIR__ . '/../Fixtures/ActionResultDto/_stubs.php',
                __DIR__ . '/../Fixtures/ActionResultDto/UnionArrayReturn.php',
            ],
            [
                [
                    'Action UnionArrayReturn::execute() must not return array — return a Result DTO instead (ADR-0020 Input/Result DTO Split).',
                    13,
                ],
            ],
        );
    }

    public function testFlagsIterableReturn(): void
    {
        $this->analyse(
            [__DIR__ . '/../Fixtures/ActionResultDto/IterableReturn.php'],
            [
                [
                    'Action IterableReturn::execute() must not return array — return a Result DTO instead (ADR-0020 Input/Result DTO Split).',
                    14,
                ],
            ],
        );
    }

    public function testIgnoresResultDtoReturn(): void
    {
        $this->analyse(
            [
                __DIR__ . '/../Fixtures/ActionResultDto/_stubs.php',
                __DIR__ . '/../Fixtures/ActionResultDto/ResultDtoReturn.php',
            ],
            [],
        );
    }

    public function testIgnoresVoidReturn(): void
    {
        $this->analyse(
            [__DIR__ . '/../Fixtures/ActionResultDto/VoidReturn.php'],
            [],
        );
    }

    public function testIgnoresCollectionReturn(): void
    {
        $this->analyse(
            [
                __DIR__ . '/../Fixtures/ActionResultDto/_stubs.php',
                __DIR__ . '/../Fixtures/ActionResultDto/CollectionReturn.php',
            ],
            [],
        );
    }

    public function testIgnoresExecuteReturningArrayOutsideActions(): void
    {
        $this->analyse(
            [__DIR__ . '/../Fixtures/ActionResultDto/NonActionArrayReturn.php'],
            [],
        );
    }

    public function testIgnoresArrayReturningPrivateHelper(): void
    {
        // Only the `execute()` boundary is policed — a private helper that
        // returns `array` inside an Action is a legitimate internal shuttle.
        $this->analyse(
            [
                __DIR__ . '/../Fixtures/ActionResultDto/_stubs.php',
                __DIR__ . '/../Fixtures/ActionResultDto/ArrayHelperNonExecute.php',
            ],
            [],
        );
    }

    public function testIgnoresPhpdocOnlyArrayReturn(): void
    {
        // Documented deliberate miss: signature-only, no phpdoc-shape chasing.
        // An untyped `execute()` with `@return array{...}` does NOT fire.
        $this->analyse(
            [__DIR__ . '/../Fixtures/ActionResultDto/PhpdocOnlyArrayReturn.php'],
            [],
        );
    }

    public function testRuleResolvesFromExtensionNeonAndFires(): void
    {
        // End-to-end pin on the extension.neon registration path consumers
        // actually use: resolve the rule from the PHPStan container so the
        // shipped `class` + `tags: [phpstan.rules.rule]` wiring is exercised —
        // NOT the PHP constructor. A dropped/mistyped `tags` line would silently
        // un-register the rule while self-CI (which builds it via `new`) stays
        // green — the NEON no-op class the package's history records (the v0.3
        // EnforceResourceDataValidatorOptInRule double-backslash defect). The
        // rule takes no constructor args, so there is no %parameter% quoting to
        // regress, but the registration itself must still be pinned.
        $this->ruleOverride = self::getContainer()->getByType(EnforceActionResultDtoRule::class);

        $this->analyse(
            [__DIR__ . '/../Fixtures/ActionResultDto/BareArrayReturn.php'],
            [
                [
                    'Action BareArrayReturn::execute() must not return array — return a Result DTO instead (ADR-0020 Input/Result DTO Split).',
                    15,
                ],
            ],
        );
    }

    /**
     * Load the shipped extension.neon so the container-resolved test can pull
     * the rule out through its actual registration.
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
        return $this->ruleOverride ?? new EnforceActionResultDtoRule;
    }
}
