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

    protected function getRule(): Rule
    {
        return new EnforceActionResultDtoRule;
    }
}
