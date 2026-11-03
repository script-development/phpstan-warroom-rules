<?php

declare(strict_types = 1);

namespace ScriptDevelopment\PhpstanWarroomRules\Tests\DiffGuards;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ScriptDevelopment\PhpstanWarroomRules\DiffGuards\CannotRun;
use ScriptDevelopment\PhpstanWarroomRules\DiffGuards\IgnoreEntries;

final class IgnoreEntriesTest extends TestCase
{
    public function testEntriesFoldToACountPerIdentifierAndPath(): void
    {
        $entries = IgnoreEntries::parse('phpstan-baseline.neon', <<<'EOF'
            parameters:
            	ignoreErrors:
            		-
            			message: '#^first wording$#'
            			identifier: argument.type
            			count: 2
            			path: app/Foo.php
            		-
            			message: '#^second wording$#'
            			identifier: argument.type
            			count: 1
            			path: app/Foo.php
            		-
            			identifier: argument.type
            			count: 4
            			path: app/Bar.php
            EOF);

        self::assertSame(['argument.type @ app/Foo.php' => 3, 'argument.type @ app/Bar.php' => 4], $entries->counts);
        self::assertSame(['argument.type @ app/Foo.php' => 'app/Foo.php', 'argument.type @ app/Bar.php' => 'app/Bar.php'], $entries->subjects);
    }

    public function testAnUncountedEntryIsUnboundedAndStaysUnboundedWhenSummed(): void
    {
        $entries = IgnoreEntries::parse('phpstan.neon', <<<'EOF'
            parameters:
                ignoreErrors:
                    -
                        identifier: staticMethod.dynamicCall
                        path: app/*
                    -
                        identifier: staticMethod.dynamicCall
                        count: 2
                        path: app/*
                    -
                        identifier: method.notFound
                        count: 2
                        path: tests/*
                    -
                        identifier: method.notFound
                        path: tests/*
            EOF);

        self::assertSame(['staticMethod.dynamicCall @ app/*' => null, 'method.notFound @ tests/*' => null], $entries->counts);
    }

    public function testMessageOnlyEntriesKeyOnTheirPatternAndPathListsAreSorted(): void
    {
        $entries = IgnoreEntries::parse('phpstan.neon', <<<'EOF'
            parameters:
                ignoreErrors:
                    - '#^a bare pattern$#'
                    -
                        messages:
                            - '#^z$#'
                            - '#^a$#'
                        paths:
                            - routes/web.php
                            - app/Http/Kernel.php
                    -
                        rawMessage: 'Exact text.'
                        count: 1
                        path: config/app.php
            EOF);

        self::assertSame([
            '#^a bare pattern$# @ (any path)' => null,
            '#^a$# | #^z$# @ app/Http/Kernel.php, routes/web.php' => null,
            'Exact text. @ config/app.php' => 1,
        ], $entries->counts);
        self::assertSame('', $entries->subjects['#^a bare pattern$# @ (any path)']);
    }

    public function testAFileWithoutIgnoreErrorsHasNoEntries(): void
    {
        self::assertSame([], IgnoreEntries::parse('phpstan.neon', "parameters:\n    level: max\n")->counts);
        self::assertSame([], IgnoreEntries::parse('phpstan.neon', '')->counts);
    }

    public function testMergeSumsAcrossFilesAsPhpstanFoldsIncludes(): void
    {
        $config = IgnoreEntries::parse('phpstan.neon', "parameters:\n    ignoreErrors:\n        -\n            identifier: a.b\n            count: 1\n            path: x.php\n");
        $baseline = IgnoreEntries::parse('phpstan-baseline.neon', "parameters:\n    ignoreErrors:\n        -\n            identifier: a.b\n            count: 2\n            path: x.php\n        -\n            identifier: c.d\n            path: y.php\n");

        $merged = IgnoreEntries::merge([$config, $baseline]);

        self::assertSame(['a.b @ x.php' => 3, 'c.d @ y.php' => null], $merged->counts);
        self::assertSame(['a.b @ x.php' => 'x.php', 'c.d @ y.php' => 'y.php'], $merged->subjects);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function malformed(): iterable
    {
        yield 'not NEON' => ["parameters:\n  ignoreErrors: [\n"];
        yield 'parameters a scalar' => ["parameters: 3\n"];
        yield 'ignoreErrors a scalar' => ["parameters:\n    ignoreErrors: 3\n"];
        yield 'entry neither pattern nor map' => ["parameters:\n    ignoreErrors:\n        - 3\n"];
        yield 'count not an integer' => ["parameters:\n    ignoreErrors:\n        -\n            identifier: a.b\n            count: two\n"];
        yield 'paths not a list' => ["parameters:\n    ignoreErrors:\n        -\n            identifier: a.b\n            paths: x.php\n"];
    }

    #[DataProvider('malformed')]
    public function testAMalformedFileCannotBeCompared(string $contents): void
    {
        try {
            IgnoreEntries::parse('phpstan.neon', $contents);
            self::fail('A malformed ignore file was read as comparable.');
        } catch (CannotRun $exception) {
            self::assertSame(CannotRun::INVALID, $exception->kind);
            self::assertStringStartsWith('phpstan.neon', $exception->getMessage());
        }
    }
}
