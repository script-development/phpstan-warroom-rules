<?php

declare(strict_types = 1);

namespace ScriptDevelopment\PhpstanWarroomRules\Tests\Rules;

use PHPStan\Analyser\FileAnalyser;
use PHPStan\Collectors\Registry as CollectorRegistry;
use PHPStan\Rules\Registry as RuleRegistry;
use PHPStan\Testing\PHPStanTestCase;

/**
 * A consumer that sets `reportIgnoresWithoutComments: false` after including
 * `extension.neon` turns the option off again.
 */
final class ReportIgnoresWithoutCommentsConsumerOverrideTest extends PHPStanTestCase
{
    public function testAConsumerSettingWinsOverTheShippedOne(): void
    {
        $file = __DIR__ . '/../Fixtures/IgnoreComments/IgnoreWithoutReason.php';
        $container = self::getContainer();

        self::assertFalse($container->getParameter('reportIgnoresWithoutComments'));

        $result = $container->getByType(FileAnalyser::class)->analyseFile(
            $file,
            [$file => true],
            $container->getByType(RuleRegistry::class),
            $container->getByType(CollectorRegistry::class),
            null,
        );

        self::assertSame([], $result->getErrors());
        self::assertCount(1, $result->getLocallyIgnoredErrors());
    }

    /**
     * @return array<int, string>
     */
    public static function getAdditionalConfigFiles(): array
    {
        return [
            __DIR__ . '/../../extension.neon',
            __DIR__ . '/../Fixtures/IgnoreComments/consumer-override.neon',
        ];
    }
}
