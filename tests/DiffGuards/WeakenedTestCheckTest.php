<?php

declare(strict_types = 1);

namespace ScriptDevelopment\PhpstanWarroomRules\Tests\DiffGuards;

use PHPUnit\Framework\TestCase;
use ScriptDevelopment\PhpstanWarroomRules\DiffGuards\Application;

use function count;

final class WeakenedTestCheckTest extends TestCase
{
    private const string PHPUNIT = <<<'EOF'
        <?php

        final class InvoiceTest extends TestCase
        {
            public function testTotals(): void
            {
                self::assertSame(3, total([1, 2]));
            }

            #[Test]
            public function rounding(): void
            {
                $this->assertRounded(1.005);
            }

            /** @test */
            public function currency(): void
            {
                self::assertSame('EUR', currency());
            }

            private function assertRounded(float $value): void
            {
                self::assertSame(1.01, round($value, 2));
                self::assertIsFloat($value);
            }
        }
        EOF;

    private const string PEST = <<<'EOF'
        <?php

        describe('invoices', function () {
            it('totals', function () {
                expect(total([1, 2]))->toBe(3);
            });

            test('rounds', fn () => expect(round(1.005, 2))->toBe(1.01));
        });

        it('formats', function (int $amount) {
            expect(format($amount))->toBeString();
        })->with([1, 2]);
        EOF;

    public function testAnUntouchedSuiteAndADatasetDrivenTestAreSilent(): void
    {
        $repository = $this->base()->branch('feature')
            ->write('tests/Unit/InvoiceTest.php', self::PHPUNIT . "\n// touched\n")
            ->write('tests/Feature/InvoicePest.php', str_replace('->with([1, 2])', '->with([1, 2, 3])', self::PEST))
            ->commit();

        [$exit, $output] = $this->guard($repository);

        self::assertSame(0, $exit, $output);
        self::assertStringContainsString("weakened-test: 0 findings over 2 changed test files\n", $output);
    }

    public function testANewSkipFailsInBothFrameworks(): void
    {
        $repository = $this->base()->branch('feature')
            ->write('tests/Unit/InvoiceTest.php', str_replace('        self::assertSame(3, total([1, 2]));', "        \$this->markTestSkipped('flaky');\n        self::assertSame(3, total([1, 2]));", self::PHPUNIT))
            ->write('tests/Feature/InvoicePest.php', str_replace("    });\n\n    test(", "    })->skip();\n\n    test(", self::PEST))
            ->commit();

        [$exit, $output] = $this->guard($repository);

        self::assertSame(1, $exit, $output);
        self::assertStringContainsString('tests/Unit/InvoiceTest.php:5  Test InvoiceTest::testTotals is now skipped.', $output);
        self::assertStringContainsString('tests/Feature/InvoicePest.php:4  Test invoices > it totals is now skipped.', $output);
        self::assertStringContainsString("weakened-test: 2 findings over 2 changed test files\n", $output);
    }

    public function testIncompleteAndTodoCountAsSkips(): void
    {
        $repository = $this->base()->branch('feature')
            ->write('tests/Unit/InvoiceTest.php', str_replace("        self::assertSame('EUR', currency());", "        \$this->markTestIncomplete();\n        self::assertSame('EUR', currency());", self::PHPUNIT))
            ->write('tests/Feature/InvoicePest.php', str_replace('->with([1, 2]);', '->with([1, 2])->todo();', self::PEST))
            ->commit();

        [, $output] = $this->guard($repository);

        self::assertStringContainsString('Test InvoiceTest::currency is now skipped.', $output);
        self::assertStringContainsString('Test it formats is now skipped.', $output);
    }

    public function testASkipThatExistedAtTheBaseAndANewSkippedTestAreSilent(): void
    {
        $skipped = str_replace('->with([1, 2]);', "->with([1, 2])->skip('needs a printer');", self::PEST);
        $repository = $this->base();
        $repository->write('tests/Feature/InvoicePest.php', $skipped)->commit();
        $repository->branch('feature')
            ->write('tests/Feature/InvoicePest.php', $skipped . "\nit('prints', function () {\n    expect(true)->toBeBool();\n})->todo();\n")
            ->commit();

        [$exit, $output] = $this->guard($repository);

        self::assertSame(0, $exit, $output);
    }

    public function testAnAssertionThatCannotFailFails(): void
    {
        $repository = $this->base()->branch('feature')
            ->write('tests/Unit/InvoiceTest.php', str_replace('self::assertSame(3, total([1, 2]));', 'self::assertTrue(true);', self::PHPUNIT))
            ->write('tests/Feature/InvoicePest.php', str_replace('expect(total([1, 2]))->toBe(3);', 'expect(true)->toBeTrue();', self::PEST))
            ->commit();

        [$exit, $output] = $this->guard($repository);

        self::assertSame(1, $exit, $output);
        self::assertStringContainsString('Test InvoiceTest::testTotals gained an assertion that cannot fail.', $output);
        self::assertStringContainsString('Test invoices > it totals gained an assertion that cannot fail.', $output);
    }

    public function testEveryTrivialShapeIsRecognisedInANewTest(): void
    {
        $shapes = [
            'self::assertTrue(TRUE);',
            '$this->assertFalse(false);',
            'self::assertNull(null);',
            'self::assertSame($a, $a);',
            'self::assertEquals(1, 1);',
            '$this->addToAssertionCount(1);',
            'expect(false)->toBeFalse();',
            'expect(null)->toBeNull();',
            "expect('x')->toBe('x');",
            'expect(1)->toEqual(1);',
        ];
        $tests = '';

        foreach ($shapes as $index => $shape) {
            $tests .= "\ntest('shape {$index}', function () {\n    \$a = 1;\n    {$shape}\n});\n";
        }

        $repository = $this->base()->branch('feature')->write('tests/Unit/ShapesPest.php', "<?php\n" . $tests)->commit();

        [, $output] = $this->guard($repository);

        self::assertStringContainsString('weakened-test: ' . count($shapes) . ' findings over 1 changed test file', $output);
    }

    public function testAssertionsThatCanFailAreNotTrivial(): void
    {
        $tests = "<?php\ntest('real', function () {\n    \$a = 1;\n    self::assertTrue(\$a === 1);\n    self::assertSame(1, \$a);\n    self::assertNull(\$a);\n    self::assertFalse(\$a);\n    expect(\$a)->toBeTrue();\n    expect(true)->toBe(false);\n    expect(1)->toBeInt();\n    expect(\$a)->toBe(\$a);\n});\n";
        $repository = $this->base()->branch('feature')->write('tests/Unit/RealPest.php', $tests)->commit();

        [$exit, $output] = $this->guard($repository);

        self::assertSame(0, $exit, $output);
    }

    public function testATestStrippedOfEveryAssertionFails(): void
    {
        $repository = $this->base()->branch('feature')
            ->write('tests/Unit/InvoiceTest.php', str_replace('$this->assertRounded(1.005);', 'round(1.005, 2);', self::PHPUNIT))
            ->commit();

        [$exit, $output] = $this->guard($repository);

        self::assertSame(1, $exit, $output);
        self::assertStringContainsString('tests/Unit/InvoiceTest.php:10  Test InvoiceTest::rounding made 2 assertion(s) at the base and makes none now.', $output);
    }

    public function testFewerAssertionsAboveZeroAreSilent(): void
    {
        $repository = $this->base()->branch('feature')
            ->write('tests/Unit/InvoiceTest.php', str_replace("        self::assertIsFloat(\$value);\n", '', self::PHPUNIT))
            ->commit();

        self::assertSame(0, $this->guard($repository)[0]);
    }

    public function testADeletedTestFails(): void
    {
        $repository = $this->base()->branch('feature')
            ->write('tests/Feature/InvoicePest.php', str_replace("\n    test('rounds', fn () => expect(round(1.005, 2))->toBe(1.01));\n", '', self::PEST))
            ->commit();

        [$exit, $output] = $this->guard($repository);

        self::assertSame(1, $exit, $output);
        self::assertStringContainsString('tests/Feature/InvoicePest.php:1  Test invoices > rounds was deleted, and no test in this change replaces it.', $output);
    }

    public function testADeletedTestFileFailsForEachTest(): void
    {
        $repository = $this->base()->branch('feature')->delete('tests/Unit/InvoiceTest.php')->commit();

        [$exit, $output] = $this->guard($repository);

        self::assertSame(1, $exit, $output);
        self::assertSame(3, mb_substr_count($output, 'was deleted'), $output);
        self::assertStringContainsString("weakened-test: 3 findings over 1 changed test file\n", $output);
    }

    public function testARenamedTestThatAssertsAsMuchIsSilent(): void
    {
        $repository = $this->base()->branch('feature')
            ->write('tests/Unit/InvoiceTest.php', str_replace(['testTotals', 'self::assertSame(3, total([1, 2]));'], ['testItTotalsTwoItems', 'self::assertSame(3, total([2, 1]));'], self::PHPUNIT))
            ->write('tests/Feature/InvoicePest.php', str_replace("test('rounds'", "test('rounds half up'", self::PEST))
            ->commit();

        [$exit, $output] = $this->guard($repository);

        self::assertSame(0, $exit, $output);
    }

    public function testARenameToAWeakerTestFails(): void
    {
        $repository = $this->base()->branch('feature')
            ->write('tests/Unit/InvoiceTest.php', str_replace(['public function rounding', '$this->assertRounded(1.005);'], ['public function roundingWorks', 'self::assertIsFloat(1.0);'], self::PHPUNIT))
            ->commit();

        [$exit, $output] = $this->guard($repository);

        self::assertSame(1, $exit, $output);
        self::assertStringContainsString('Test InvoiceTest::rounding was deleted', $output);
    }

    public function testOneNewTestReplacesOneDeletedTestOnly(): void
    {
        $repository = $this->base()->branch('feature')
            ->write('tests/Feature/InvoicePest.php', "<?php\n\nit('totals and rounds', function () {\n    expect(total([1, 2]))->toBe(3);\n});\n\nit('formats', function (int \$amount) {\n    expect(format(\$amount))->toBeString();\n})->with([1, 2]);\n")
            ->commit();

        [$exit, $output] = $this->guard($repository);

        self::assertSame(1, $exit, $output);
        self::assertSame(1, mb_substr_count($output, 'was deleted'), $output);
    }

    public function testATestMovedToAnotherFileKeepsItsName(): void
    {
        $repository = $this->base()->branch('feature')
            ->move('tests/Feature/InvoicePest.php', 'tests/Feature/Billing/InvoicePest.php')
            ->commit();

        [$exit, $output] = $this->guard($repository);

        self::assertSame(0, $exit, $output);
        self::assertStringContainsString('over 1 changed test file', $output);
    }

    public function testFilesOutsideTheTestDirectoriesAreNotRead(): void
    {
        $repository = $this->base()->branch('feature')->write('app/Invoice.php', "<?php\nfunction total() { self::assertTrue(true); }\n")->delete('tests/fixtures.json')->commit();

        [$exit, $output] = $this->guard($repository);

        self::assertSame(0, $exit, $output);
        self::assertStringContainsString('weakened-test: compared nothing — no changed test files in this change', $output);
    }

    public function testATestFileThatDoesNotParseCannotBeCompared(): void
    {
        $repository = $this->base()->branch('feature')->write('tests/Unit/InvoiceTest.php', "<?php\nclass {\n")->commit();

        [$exit, $output] = $this->guard($repository);

        self::assertSame(2, $exit, $output);
        self::assertStringContainsString('INVALID — tests/Unit/InvoiceTest.php does not parse as PHP', $output);
    }

    public function testAWaiverExcusesAWeakenedTestFile(): void
    {
        $repository = $this->base()->branch('feature')->delete('tests/Unit/InvoiceTest.php')
            ->write('warroom-diff-guards.json', '{"waivers": [{"check": "weakened-test", "path": "tests/Unit/InvoiceTest.php", "reason": "The invoice module was removed in this change."}]}')
            ->commit();

        self::assertSame(0, $this->guard($repository)[0]);
    }

    public function testConfiguredTestDirectoriesReplaceTheDefault(): void
    {
        $repository = $this->base()->branch('feature')->delete('tests/Unit/InvoiceTest.php')
            ->write('warroom-diff-guards.json', '{"tests": ["spec"]}')
            ->commit();

        self::assertSame(0, $this->guard($repository)[0]);
    }

    public function testAssertionsMovedIntoASameFileHelperOrAnArrowFunctionStillCount(): void
    {
        $repository = $this->base()->branch('feature')
            ->write('tests/Feature/InvoicePest.php', str_replace('expect(total([1, 2]))->toBe(3);', 'checkTotals();', self::PEST) . "\nfunction checkTotals(): void\n{\n    expect(total([1, 2]))->toBe(3);\n}\n")
            ->commit();

        self::assertSame(0, $this->guard($repository)[0]);

        $repository->write('tests/Feature/InvoicePest.php', str_replace('fn () => expect(round(1.005, 2))->toBe(1.01)', 'fn () => round(1.005, 2)', self::PEST))->commit();

        [$exit, $output] = $this->guard($repository);

        self::assertSame(1, $exit, $output);
        self::assertStringContainsString('Test invoices > rounds made 1 assertion(s) at the base and makes none now.', $output);
    }

    public function testOnlyPublicTestMethodsAreTests(): void
    {
        $withHelpers = str_replace('    private function assertRounded', "    public function helper(): void\n    {\n        self::assertTrue(1 === 1);\n    }\n\n    private function testLooking(): void\n    {\n        self::assertTrue(1 === 1);\n    }\n\n    private function assertRounded", self::PHPUNIT);
        $repository = $this->base();
        $repository->write('tests/Unit/InvoiceTest.php', $withHelpers)->commit();
        $repository->branch('feature')->write('tests/Unit/InvoiceTest.php', self::PHPUNIT)->commit();

        [$exit, $output] = $this->guard($repository);

        self::assertSame(0, $exit, $output);
    }

    public function testATestThatNeverAssertedMayStaySo(): void
    {
        $repository = $this->base();
        $repository->write('tests/Unit/SmokePest.php', "<?php\ntest('boots', function () {\n    boot();\n});\n")->commit();
        $repository->branch('feature')->write('tests/Unit/SmokePest.php', "<?php\ntest('boots', function () {\n    boot(true);\n});\n")->commit();

        self::assertSame(0, $this->guard($repository)[0]);
    }

    public function testASiblingDirectorySharingThePrefixIsNotATestDirectory(): void
    {
        $repository = $this->base();
        $repository->write('tests-legacy/OldPest.php', "<?php\ntest('old', function () {\n    expect(1)->toBe(1);\n});\n")->commit();
        $repository->branch('feature')->delete('tests-legacy/OldPest.php')->write('app/A.php', '<?php')->delete('tests/Unit/InvoiceTest.php')->commit();

        [$exit, $output] = $this->guard($repository);

        self::assertSame(1, $exit, $output);
        self::assertStringNotContainsString('old was deleted', $output);
        self::assertStringContainsString("weakened-test: 3 findings over 1 changed test file\n", $output);
    }

    public function testAReplacementIsMeasuredByAssertionsThatCanFail(): void
    {
        $repository = $this->base();
        $repository->write('tests/Unit/PadPest.php', "<?php\ntest('pads', function () {\n    expect(pad(1))->toBe('01');\n    expect(true)->toBeTrue();\n});\n")->commit();
        $repository->branch('feature')->write('tests/Unit/PadPest.php', "<?php\ntest('pads one digit', function () {\n    expect(pad(1))->toBe('01');\n});\n")->commit();

        self::assertSame(0, $this->guard($repository)[0]);
    }

    private function base(): GitRepository
    {
        return (new GitRepository)
            ->write('tests/Unit/InvoiceTest.php', self::PHPUNIT)
            ->write('tests/Feature/InvoicePest.php', self::PEST)
            ->write('tests/fixtures.json', '{}')
            ->commit('base');
    }

    /**
     * @return array{int, string}
     */
    private function guard(GitRepository $repository): array
    {
        $output = fopen('php://memory', 'w+');
        self::assertIsResource($output);

        $exit = (new Application)->run(['--base=main'], $repository->root, $output);
        rewind($output);

        return [$exit, (string) stream_get_contents($output)];
    }
}
