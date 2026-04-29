<?php

declare(strict_types = 1);

namespace ScriptDevelopment\PhpstanWarroomRules\Tests\Rules;

use PHPStan\Rules\Rule;
use PHPStan\Testing\RuleTestCase;
use ScriptDevelopment\PhpstanWarroomRules\Rules\ForbidAbortHelperRule;

/**
 * @extends RuleTestCase<ForbidAbortHelperRule>
 */
final class ForbidAbortHelperRuleTest extends RuleTestCase
{
    public function testFlagsAbortCall(): void
    {
        $this->analyse(
            [__DIR__ . '/../Fixtures/AbortHelper/UsesAbort.php'],
            [
                [
                    'Use of abort() is forbidden. Throw a proper HTTP exception instead (e.g., NotFoundHttpException, UnauthorizedHttpException, HttpException).',
                    9,
                ],
            ],
        );
    }

    public function testFlagsAbortIfCall(): void
    {
        $this->analyse(
            [__DIR__ . '/../Fixtures/AbortHelper/UsesAbortIf.php'],
            [
                [
                    'Use of abort_if() is forbidden. Throw a proper HTTP exception instead (e.g., NotFoundHttpException, UnauthorizedHttpException, HttpException).',
                    9,
                ],
            ],
        );
    }

    public function testIgnoresExplicitException(): void
    {
        $this->analyse(
            [__DIR__ . '/../Fixtures/AbortHelper/ThrowsHttpException.php'],
            [],
        );
    }

    protected function getRule(): Rule
    {
        return new ForbidAbortHelperRule;
    }
}
