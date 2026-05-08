<?php

declare(strict_types = 1);

namespace ScriptDevelopment\PhpstanWarroomRules\Tests\Rules;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function sprintf;

/**
 * Locks the package's own rule-author convention: every
 * `RuleErrorBuilder::message()->identifier(...)` call in `src/Rules/*.php` must
 * use a `cameLCase.cameLCase` identifier so consumers see a uniform shape across
 * every rule the package ships.
 *
 * Doctrine source: ADR-0021 §Identifier convention.
 *
 * Like RuleDocblockContractTest, this enforces rule-authoring discipline (a
 * lexical contract on identifier strings), not rule enforcement.
 */
final class RuleIdentifierConventionTest extends TestCase
{
    private const string IDENTIFIER_PATTERN = '/->identifier\(\s*[\'"]([^\'"]+)[\'"]\s*\)/';

    private const string CONVENTION_PATTERN = '/^[a-z][a-zA-Z0-9]*\.[a-z][a-zA-Z0-9]*$/';

    #[Test]
    public function every_rule_identifier_follows_camel_dot_camel_convention(): void
    {
        $ruleFiles = glob(__DIR__ . '/../../src/Rules/*.php');

        self::assertNotEmpty($ruleFiles, 'No rule files found under src/Rules');

        $allIdentifiers = [];
        foreach ($ruleFiles as $file) {
            $source = file_get_contents($file);
            self::assertNotFalse($source, "Could not read {$file}");

            preg_match_all(self::IDENTIFIER_PATTERN, $source, $matches);

            foreach ($matches[1] as $identifier) {
                $allIdentifiers[] = [$file, $identifier];
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

        self::assertNotEmpty(
            $allIdentifiers,
            'No identifiers found across rule files — regex broken or rules lack ->identifier() calls.',
        );
    }
}
