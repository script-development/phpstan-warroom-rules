<?php

declare(strict_types = 1);

namespace ScriptDevelopment\PhpstanWarroomRules\Tests\Rules;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Locks the package's own rule-author convention: every class under `src/Rules/`
 * must declare a `Doctrine source:` line in its class-level docblock so the rule's
 * authority (ADR or war-room principle) is visible at the source.
 *
 * Doctrine source: ADR-0021 §Doctrine source in docblock.
 *
 * This test enforces *how rules are written*, not *what rules check*. It does not
 * contradict the package's "static-analysis library only" stance (CLAUDE.md
 * §"What this territory does NOT do") because PHPUnit reflection on rule classes
 * is rule-authoring discipline, not rule enforcement.
 */
final class RuleDocblockContractTest extends TestCase
{
    #[Test]
    public function every_rule_class_declares_doctrine_source_in_class_docblock(): void
    {
        $ruleFiles = glob(__DIR__ . '/../../src/Rules/*.php');

        self::assertNotEmpty($ruleFiles, 'No rule files found under src/Rules');

        foreach ($ruleFiles as $file) {
            $namespace = 'ScriptDevelopment\PhpstanWarroomRules\Rules';
            $class = $namespace . '\\' . basename($file, '.php');

            self::assertTrue(class_exists($class), "Rule class not autoloadable: {$class}");

            $docblock = (new ReflectionClass($class))->getDocComment();

            self::assertNotFalse(
                $docblock,
                "{$class} has no class-level docblock (ADR-0021 §Doctrine source in docblock).",
            );

            self::assertStringContainsString(
                'Doctrine source:',
                $docblock,
                "{$class} class-level docblock does not name its doctrine source. ADR-0021 §Doctrine source in docblock requires every rule to cite its ADR or war-room principle.",
            );
        }
    }
}
