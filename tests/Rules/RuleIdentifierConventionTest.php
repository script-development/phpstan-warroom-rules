<?php

declare(strict_types = 1);

namespace ScriptDevelopment\PhpstanWarroomRules\Tests\Rules;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ScriptDevelopment\PhpstanWarroomRules\Tests\Support\RuleIdentifierExtractor;

use function basename;
use function count;
use function glob;
use function implode;
use function sprintf;

/**
 * Locks the package's own rule-author convention: every PHPStan error
 * identifier a rule hands to `RuleErrorBuilder::identifier()` must read
 * `cameLCase.cameLCase`, so consumers see one shape across every rule shipped.
 *
 * Doctrine source: ADR-0021 §Identifier convention.
 *
 * The assertion is PER RULE FILE, and that is the whole point. The predecessor
 * scanned every rule into one aggregate list and asserted only that the list
 * was non-empty — true while seventeen rules contributed and the eighteenth
 * contributed nothing, which is what happened: `EnforceAuditModelProtectionsRule`
 * routes three identifiers through a private helper, matched no literal, and
 * went unchecked with the suite green. WR-0853. A file that yields no readable
 * identifier now fails under its own name, and no exemption list exists to put
 * it back to sleep.
 *
 * Like RuleDocblockContractTest, this enforces rule-authoring discipline (a
 * lexical contract on identifier strings), not rule enforcement.
 */
final class RuleIdentifierConventionTest extends TestCase
{
    private const string CONVENTION_PATTERN = '/^[a-z][a-zA-Z0-9]*\.[a-z][a-zA-Z0-9]*$/';

    /**
     * @return iterable<string, array{string}>
     */
    public static function ruleFiles(): iterable
    {
        $files = glob(__DIR__ . '/../../src/Rules/*.php');

        foreach ($files === false ? [] : $files as $file) {
            yield basename($file) => [$file];
        }
    }

    #[DataProvider('ruleFiles')]
    #[Test]
    public function rule_identifiers_are_readable_and_follow_the_convention(string $file): void
    {
        $extracted = RuleIdentifierExtractor::fromFile($file);

        self::assertSame(
            [],
            $extracted['unresolved'],
            sprintf(
                "%s hands identifier() something this test cannot read, so those identifiers are unchecked:\n%s\nHoist the value to a string constant of the same class, or pass it as a literal.",
                basename($file),
                implode("\n", $extracted['unresolved']),
            ),
        );

        self::assertNotEmpty(
            $extracted['identifiers'],
            sprintf(
                '%s contributes no readable error identifier. Every rule reports through RuleErrorBuilder::identifier(); a rule that yields none here is exempt from the convention check by accident, which is the defect this assertion exists to prevent (WR-0853).',
                basename($file),
            ),
        );

        foreach ($extracted['identifiers'] as $identifier) {
            self::assertMatchesRegularExpression(
                self::CONVENTION_PATTERN,
                $identifier,
                sprintf(
                    'Identifier "%s" in %s does not follow ADR-0021 §Identifier convention (cameLCase.cameLCase).',
                    $identifier,
                    basename($file),
                ),
            );
        }
    }

    /**
     * Denominator guard for the provider above. A broken glob yields no data
     * sets, and PHPUnit reports an empty provider as a warning on a run this
     * suite would otherwise call green — the same shape of silent zero the
     * per-file assertion closes one level down.
     */
    #[Test]
    public function the_provider_sees_every_rule_file_on_disk(): void
    {
        $provided = 0;

        foreach (self::ruleFiles() as $_) {
            $provided++;
        }

        $onDisk = glob(__DIR__ . '/../../src/Rules/*.php');

        self::assertNotEmpty($onDisk, 'No rule files found under src/Rules — the scan is broken, not the code.');
        self::assertSame(count($onDisk), $provided, 'The data provider does not cover every file in src/Rules.');
    }
}
