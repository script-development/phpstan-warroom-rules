<?php

declare(strict_types = 1);

namespace ScriptDevelopment\PhpstanWarroomRules\Tests\Rules;

use PHPStan\Analyser\Error;
use PHPStan\Analyser\FileAnalyser;
use PHPStan\Collectors\Registry as CollectorRegistry;
use PHPStan\Rules\Registry as RuleRegistry;
use PHPStan\Testing\PHPStanTestCase;

/**
 * `extension.neon` turns on PHPStan's own `reportIgnoresWithoutComments`. A
 * `RuleTestCase` constructs its `FileAnalyser` with that option hard-coded off,
 * so the suite reads it the way a consumer run does: through the container's
 * `FileAnalyser`, with the shipped `extension.neon` loaded.
 */
final class ReportIgnoresWithoutCommentsTest extends PHPStanTestCase
{
    private const string NOT_FOUND = 'function.notFound: Function undefinedFixtureHelper not found.';

    public function testTheShippedConfigTurnsTheOptionOn(): void
    {
        self::assertTrue(self::getContainer()->getParameter('reportIgnoresWithoutComments'));
    }

    public function testAnIgnoreWithoutAReasonIsReportedAndStopsSuppressing(): void
    {
        self::assertSame(
            [
                '9 ' . self::NOT_FOUND,
                '9 ignore.noComment: Ignore with identifier function.notFound has no comment.',
            ],
            $this->errors('IgnoreWithoutReason.php'),
        );
    }

    public function testEmptyParenthesesAreNotAReason(): void
    {
        self::assertSame(
            [
                '9 ' . self::NOT_FOUND,
                '9 ignore.noComment: Ignore with identifier function.notFound has no comment.',
            ],
            $this->errors('IgnoreWithEmptyReason.php'),
        );
    }

    public function testAnIgnoreWithAReasonSuppressesOnTheSameLineAndFromTheLineAbove(): void
    {
        self::assertSame([], $this->errors('IgnoreWithReason.php'));
    }

    public function testIgnoreLineIsReportedWhateverTextFollowsIt(): void
    {
        self::assertSame(
            [
                '9 ' . self::NOT_FOUND,
                '9 ignore.allLineErrors: Ignoring all errors on a line is not allowed.',
            ],
            $this->errors('IgnoreLine.php'),
        );
    }

    public function testIgnoreNextLineIsReportedWhateverTextFollowsIt(): void
    {
        self::assertSame(
            [
                '10 ' . self::NOT_FOUND,
                '10 ignore.allLineErrors: Ignoring all errors on a line is not allowed.',
            ],
            $this->errors('IgnoreNextLine.php'),
        );
    }

    /**
     * @return array<int, string>
     */
    public static function getAdditionalConfigFiles(): array
    {
        return [
            __DIR__ . '/../../extension.neon',
        ];
    }

    /**
     * @return list<string>
     */
    private function errors(string $fixture): array
    {
        $file = __DIR__ . '/../Fixtures/IgnoreComments/' . $fixture;
        $container = self::getContainer();

        $result = $container->getByType(FileAnalyser::class)->analyseFile(
            $file,
            [$file => true],
            $container->getByType(RuleRegistry::class),
            $container->getByType(CollectorRegistry::class),
            null,
        );

        $errors = array_map(
            static fn(Error $error): string => $error->getLine() . ' ' . $error->getIdentifier() . ': ' . $error->getMessage(),
            $result->getErrors(),
        );
        sort($errors);

        return $errors;
    }
}
