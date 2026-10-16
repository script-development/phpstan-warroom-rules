<?php

declare(strict_types = 1);

namespace ScriptDevelopment\PhpstanWarroomRules\Tests\Rules;

use Illuminate\Contracts\Cache\Repository;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

use function basename;
use function dirname;
use function glob;
use function is_array;
use function is_string;
use function mb_strtolower;
use function preg_match;
use function sprintf;
use function str_contains;

/**
 * PHP dispatches method names case-insensitively, so every rule folds a call's
 * identifier before comparing it (WR-1291). A list it compares against must
 * therefore be lower-case too: one camelCase entry is a method the rule can
 * never match. This reads every method-name constant in `src/Rules/` and
 * `src/Collectors/` — by naming convention, `*_METHOD`, `*_METHODS`,
 * `*_METHOD_NAME`, `*_VERBS`, `*_SINKS`, `*_READERS` — and asserts it.
 *
 * A list holds method names; a map keyed by method holds them as KEYS (its
 * values are parameter slots); a map keyed by class FQCN holds them as the
 * class's list.
 */
final class MethodNameVocabularyTest extends TestCase
{
    private const string VOCABULARY = '/(_METHODS?|_METHOD_NAME|_VERBS|_SINKS|_READERS)$/';

    public function testEveryMethodNameConstantIsLowerCase(): void
    {
        $files = [...glob(__DIR__ . '/../../src/Rules/*.php') ?: [], ...glob(__DIR__ . '/../../src/Collectors/*.php') ?: []];

        self::assertNotEmpty($files, 'No source files found, so the scan measures nothing.');

        $checked = 0;
        $offenders = [];

        foreach ($files as $file) {
            $class = sprintf('ScriptDevelopment\PhpstanWarroomRules\%s\%s', basename(dirname($file)), basename($file, '.php'));

            foreach ((new ReflectionClass($class))->getReflectionConstants() as $constant) {
                if (preg_match(self::VOCABULARY, $constant->getName()) !== 1) {
                    continue;
                }

                foreach ($this->methodNames($constant->getValue()) as $name) {
                    $checked++;

                    if ($name !== mb_strtolower($name)) {
                        $offenders[] = sprintf('%s::%s holds %s', $class, $constant->getName(), $name);
                    }
                }
            }
        }

        // Measured at WR-1291: 18 constants, 303 names. A floor, not a count —
        // a new rule may add more; a constant renamed out of the convention
        // drops the total below it.
        self::assertGreaterThanOrEqual(303, $checked, 'Fewer method names were read than exist, so a constant fell out of the naming convention.');
        self::assertSame([], $offenders);
    }

    public function testTheScanReadsEachShape(): void
    {
        self::assertSame(['Update'], $this->methodNames('Update'));
        self::assertSame(['save', 'Delete'], $this->methodNames(['save', 'Delete']));
        self::assertSame(['insertOrIgnore'], $this->methodNames(['insertOrIgnore' => [['values', 0]]]));
        self::assertSame(['Put'], $this->methodNames([Repository::class => ['Put']]));
    }

    /**
     * @return list<string>
     */
    private function methodNames(mixed $value): array
    {
        if (is_string($value)) {
            return [$value];
        }

        self::assertIsArray($value);

        $names = [];

        foreach ($value as $key => $entry) {
            if (is_string($key) && str_contains($key, '\\') && is_array($entry)) {
                foreach ($this->methodNames($entry) as $name) {
                    $names[] = $name;
                }

                continue;
            }

            if (is_string($key)) {
                $names[] = $key;

                continue;
            }

            self::assertIsString($entry);
            $names[] = $entry;
        }

        return $names;
    }
}
