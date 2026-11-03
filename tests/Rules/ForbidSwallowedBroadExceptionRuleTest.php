<?php

declare(strict_types = 1);

namespace ScriptDevelopment\PhpstanWarroomRules\Tests\Rules;

use PHPStan\Rules\Rule;
use PHPStan\Testing\RuleTestCase;
use ScriptDevelopment\PhpstanWarroomRules\Rules\ForbidSwallowedBroadExceptionRule;

/**
 * @extends RuleTestCase<ForbidSwallowedBroadExceptionRule>
 */
final class ForbidSwallowedBroadExceptionRuleTest extends RuleTestCase
{
    private const string TIP = 'Report it (report($e), Log::…), rethrow it wrapped in a domain exception, or catch the narrow type you expect. A deliberate fail-open (telemetry, the review bus, an error sink) takes an inline PHPStan ignore on forbidSwallowedBroadException.swallowed with the reason.';

    public function testFlagsABroadCatchWhoseFailureGoesNowhere(): void
    {
        $this->analyse(
            [__DIR__ . '/../Fixtures/SwallowedBroadException/Firing.php'],
            [
                [self::message('Catch of Throwable'), 23, self::TIP],
                [self::message('Catch of Exception'), 32, self::TIP],
                [self::message('Catch of Throwable'), 40, self::TIP],
                [self::message('Catch of Error'), 51, self::TIP],
                [self::message('Catch of Exception'), 60, self::TIP],
                [self::message('Catch of Throwable'), 71, self::TIP],
                [self::message('Catch of Throwable'), 80, self::TIP],
            ],
        );
    }

    public function testStaysSilentWhenTheFailureIsRethrownReportedOrCarried(): void
    {
        $this->analyse(
            [__DIR__ . '/../Fixtures/SwallowedBroadException/Silent.php'],
            [],
        );
    }

    public function testFoldsTheCaseOfTheCaughtTypeName(): void
    {
        $this->analyse(
            [__DIR__ . '/../Fixtures/SwallowedBroadException/CaseFolded.php'],
            [
                [self::message('Catch of THROWABLE'), 15, self::TIP],
            ],
        );
    }

    public function testANamespaceLocalClassNamedExceptionIsNotBroad(): void
    {
        $this->analyse(
            [__DIR__ . '/../Fixtures/SwallowedBroadException/LocalExceptionClass.php'],
            [],
        );
    }

    protected function getRule(): Rule
    {
        return new ForbidSwallowedBroadExceptionRule;
    }

    private static function message(string $catchOf): string
    {
        return "{$catchOf} swallows the failure: it neither rethrows, reports nor logs it, and never reads the caught exception.";
    }
}
