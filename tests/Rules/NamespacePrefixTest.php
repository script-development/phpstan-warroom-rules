<?php

declare(strict_types = 1);

namespace ScriptDevelopment\PhpstanWarroomRules\Tests\Rules;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ScriptDevelopment\PhpstanWarroomRules\Support\NamespacePrefix;

use function file_get_contents;
use function glob;
use function implode;
use function preg_match;
use function preg_match_all;

final class NamespacePrefixTest extends TestCase
{
    /**
     * @return iterable<string, array{0: string, 1: string, 2: bool}>
     */
    public static function cases(): iterable
    {
        yield 'the namespace itself' => ['App\Actions', 'App\Actions', true];
        yield 'a sub-namespace' => ['App\Actions', 'App\Actions\Billing', true];
        yield 'a deeper sub-namespace' => ['App\Actions', 'App\Actions\Billing\Invoices', true];
        yield 'a sibling sharing the opening characters' => ['App\Actions', 'App\ActionsLegacy', false];
        yield 'a sibling sharing the opening characters, nested' => ['App\Actions', 'App\ActionsLegacy\Billing', false];
        yield 'the parent namespace' => ['App\Actions', 'App', false];
        yield 'an unrelated namespace' => ['App\Actions', 'App\Http\Controllers', false];
    }

    #[DataProvider('cases')]
    public function testContainsMatchesOnTheNamespaceBoundary(string $prefix, string $namespace, bool $expected): void
    {
        self::assertSame($expected, NamespacePrefix::contains($prefix, $namespace));
        self::assertSame($expected, NamespacePrefix::containsAny(['App\Unrelated', $prefix], $namespace));
    }

    public function testContainsAnyOfNoPrefixesContainsNothing(): void
    {
        self::assertFalse(NamespacePrefix::containsAny([], 'App\Actions'));
    }

    public function testNormaliseTrimsATrailingSeparatorOnce(): void
    {
        self::assertSame(
            ['App\Http\Controllers', 'App\Http\Client\Controllers'],
            NamespacePrefix::normalise(['App\Http\Controllers\\', 'App\Http\Client\Controllers']),
        );
        self::assertTrue(NamespacePrefix::containsAny(NamespacePrefix::normalise(['App\Http\Controllers\\']), 'App\Http\Controllers'));
    }

    /**
     * The shape WR-1291 removed: a namespace read from the scope tested with a
     * bare `str_starts_with($namespace, …)`. The only form allowed is the one
     * that appends the separator.
     */
    public function testNoSourceFileTestsANamespaceWithoutTheSeparator(): void
    {
        $pattern = '/str_starts_with\(\$namespace, (?!\$prefix \. \'\\\\\\\\\'\))/';

        self::assertSame(1, preg_match($pattern, 'str_starts_with($namespace, \'App\Actions\')'), 'The pattern no longer matches the shape it forbids.');
        self::assertSame(0, preg_match($pattern, 'str_starts_with($namespace, $prefix . \'\\\\\')'), 'The pattern matches the bounded form.');

        $files = [...glob(__DIR__ . '/../../src/Rules/*.php') ?: [], ...glob(__DIR__ . '/../../src/Collectors/*.php') ?: []];

        self::assertNotEmpty($files, 'No source files found, so the scan measures nothing.');

        $offenders = [];

        foreach ($files as $file) {
            $source = file_get_contents($file);

            self::assertNotFalse($source);

            if (preg_match_all($pattern, $source) > 0) {
                $offenders[] = $file;
            }
        }

        self::assertSame([], $offenders, 'Use NamespacePrefix::contains() instead: ' . implode(', ', $offenders));
    }
}
