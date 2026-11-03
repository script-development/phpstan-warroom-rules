<?php

declare(strict_types = 1);

namespace ScriptDevelopment\PhpstanWarroomRules\Tests\DiffGuards;

use const JSON_THROW_ON_ERROR;

use PHPUnit\Framework\TestCase;
use ScriptDevelopment\PhpstanWarroomRules\DiffGuards\Application;
use ScriptDevelopment\PhpstanWarroomRules\DiffGuards\EditedMigrationCheck;
use ScriptDevelopment\PhpstanWarroomRules\DiffGuards\Git;

final class ApplicationTest extends TestCase
{
    private const string MIGRATION = 'database/migrations/2026_01_01_000000_create_users_table.php';

    private const string PARK = <<<'EOF'
        parameters:
            ignoreErrors:
                -
                    identifier: staticMethod.dynamicCall
                    count: 3
                    path: routes/console.php
        EOF;

    public function testACleanChangePassesAndStatesWhatItCompared(): void
    {
        $repository = $this->base()->branch('feature')->write('database/migrations/2026_02_01_000000_create_posts_table.php', '<?php // new')->commit();

        [$exit, $output] = $this->guard($repository->root, '--base=main');

        self::assertSame(0, $exit, $output);
        self::assertStringContainsString("edited-migration: 0 findings over 1 changed migration\n", $output);
        self::assertStringContainsString('ignore-growth: compared nothing — no changed ignore files in this change', $output);
        self::assertMatchesRegularExpression('/diff-guards: PASSED — 0 findings; compared HEAD with the merge base [0-9a-f]{12} of main over 1 changed path\n/', $output);
    }

    public function testAnEditedMigrationFailsWithItsFileAndLine(): void
    {
        $repository = $this->base()->branch('feature')->write(self::MIGRATION, '<?php // edited')->commit();

        [$exit, $output] = $this->guard($repository->root, '--base', 'main');

        self::assertSame(1, $exit, $output);
        self::assertStringContainsString('  ' . self::MIGRATION . ':1  Migration ' . self::MIGRATION . ' existed at the base', $output);
        self::assertStringContainsString("edited-migration: 1 finding over 1 changed migration\n", $output);
        self::assertStringContainsString('diff-guards: FAILED — 1 finding;', $output);
    }

    public function testAMigrationAddedOnTheBranchAndEditedAgainIsSilent(): void
    {
        $added = 'database/migrations/2026_02_01_000000_create_posts_table.php';
        $repository = $this->base()->branch('feature')->write($added, '<?php // one')->commit()->write($added, '<?php // two')->commit();

        [$exit, $output] = $this->guard($repository->root, '--base=main');

        self::assertSame(0, $exit, $output);
    }

    public function testARenamedMigrationWithIdenticalContentFails(): void
    {
        $repository = $this->base()->branch('feature')->move(self::MIGRATION, 'database/migrations/2026_09_09_000000_create_users_table.php')->commit();

        [$exit, $output] = $this->guard($repository->root, '--base=main');

        self::assertSame(1, $exit, $output);
        self::assertStringContainsString('was renamed to database/migrations/2026_09_09_000000_create_users_table.php', $output);
    }

    public function testABranchBehindItsBaseIsComparedFromTheForkPointNotTheTip(): void
    {
        $repository = $this->base()->branch('feature')->write('app/Feature.php', '<?php')->commit();
        $repository->checkout('main')->write('database/migrations/2026_03_01_000000_later.php', '<?php')->commit('later on main')
            ->write('phpstan.neon', self::PARK . "\n        -\n            identifier: a.b\n            path: x.php\n")->commit()
            ->checkout('feature');

        [$exit, $output] = $this->guard($repository->root, '--base=main');

        self::assertSame(0, $exit, $output);
        self::assertStringContainsString('over 1 changed path', $output);

        $againstTip = (new EditedMigrationCheck)->run((new Git($repository->root))->changes('main'), ['database/migrations']);
        self::assertCount(1, $againstTip->findings, 'Control: against the tip, the base\'s later migration reads as deleted by this branch.');
    }

    public function testAProjectInASubdirectoryReadsItsOwnPathsAndAnnotatesFromTheRepositoryRoot(): void
    {
        $repository = new GitRepository;
        $repository->write('backend/' . self::MIGRATION, '<?php')->write('backend/phpstan.neon', self::PARK)
            ->write(self::MIGRATION, '<?php // a migration outside the project')->commit()->branch('feature')
            ->write('backend/' . self::MIGRATION, '<?php // edited')
            ->write(self::MIGRATION, '<?php // edited outside')
            ->write('backend/phpstan.neon', str_replace('count: 3', 'count: 4', self::PARK))->commit();

        [$exit, $output] = $this->guard($repository->root . '/backend', '--base=main', '--format=github');

        self::assertSame(1, $exit, $output);
        self::assertStringContainsString('::error file=backend/' . self::MIGRATION . ',line=1,title=edited-migration::Migration ' . self::MIGRATION, $output);
        self::assertStringContainsString('::error file=backend/phpstan.neon,line=6,title=ignore-growth::Ignored error staticMethod.dynamicCall @ routes/console.php grew from count 3 to 4.', $output);
        self::assertStringContainsString("edited-migration: 1 finding over 1 changed migration\n", $output);
        self::assertStringContainsString('over 2 changed paths', $output);
    }

    public function testAParkThatShrinksOrAnEntryThatGoesPasses(): void
    {
        $repository = $this->base()->branch('feature')->write('phpstan.neon', str_replace('count: 3', 'count: 1', self::PARK))->commit();

        [$exit, $output] = $this->guard($repository->root, '--base=main');

        self::assertSame(0, $exit, $output);
        self::assertStringContainsString("ignore-growth: 0 findings over 1 changed ignore file\n", $output);
    }

    public function testANewIgnoreAndAnUncountedParkEachFail(): void
    {
        $repository = $this->base()->branch('feature')
            ->write('phpstan.neon', str_replace("            count: 3\n", '', self::PARK))
            ->write('phpstan-baseline.neon', "parameters:\n\tignoreErrors:\n\t\t-\n\t\t\tidentifier: argument.type\n\t\t\tcount: 1\n\t\t\tpath: app/Foo.php\n")
            ->commit();

        [$exit, $output] = $this->guard($repository->root, '--base=main');

        self::assertSame(1, $exit, $output);
        self::assertStringContainsString('phpstan.neon:5  Ignored error staticMethod.dynamicCall @ routes/console.php lost its count (3) and now silences without limit.', $output);
        self::assertStringContainsString('phpstan-baseline.neon:6  New ignored error: argument.type @ app/Foo.php, count 1.', $output);
        self::assertStringContainsString("ignore-growth: 2 findings over 2 changed ignore files\n", $output);
    }

    public function testAnEntryMovedIntoTheBaselineNetsToNothingAndARegeneratedBaselineIsSilent(): void
    {
        $repository = $this->base();
        $repository->write('phpstan-baseline.neon', "parameters:\n\tignoreErrors:\n\t\t-\n\t\t\tmessage: '#^old wording$#'\n\t\t\tidentifier: argument.type\n\t\t\tcount: 1\n\t\t\tpath: app/Foo.php\n")->commit();
        $repository->branch('feature')
            ->write('phpstan.neon', "parameters:\n    level: max\n")
            ->write('phpstan-baseline.neon', "parameters:\n\tignoreErrors:\n\t\t-\n\t\t\tmessage: '#^new wording$#'\n\t\t\tidentifier: argument.type\n\t\t\tcount: 1\n\t\t\tpath: app/Foo.php\n\t\t-\n\t\t\tidentifier: staticMethod.dynamicCall\n\t\t\tcount: 3\n\t\t\tpath: routes/console.php\n")
            ->commit();

        [$exit, $output] = $this->guard($repository->root, '--base=main');

        self::assertSame(0, $exit, $output);
        self::assertStringContainsString('ignore-growth: 0 findings over 2 changed ignore files', $output);
    }

    public function testAnUncountedEntryAtTheBaseMayChangeFreely(): void
    {
        $repository = $this->base();
        $repository->write('phpstan.neon', "parameters:\n    ignoreErrors:\n        -\n            identifier: a.b\n            path: x.php\n")->commit();
        $repository->branch('feature')->write('phpstan.neon', "parameters:\n    ignoreErrors:\n        -\n            identifier: a.b\n            count: 9\n            path: x.php\n")->commit();

        self::assertSame(0, $this->guard($repository->root, '--base=main')[0]);
    }

    public function testAnEntryInTwoHeadFilesIsReportedOnceOnTheFirstConfiguredFile(): void
    {
        $repository = $this->base()->branch('feature')
            ->write('phpstan.neon', str_replace('count: 3', 'count: 4', self::PARK))
            ->write('phpstan.neon.dist', self::PARK)
            ->commit();

        [$exit, $output] = $this->guard($repository->root, '--base=main');

        self::assertSame(1, $exit, $output);
        self::assertSame(1, mb_substr_count($output, 'grew from count 3 to 7'), $output);
        self::assertStringContainsString('  phpstan.neon:6  ', $output);
    }

    public function testAWaiverWithAReasonExcusesItsFindingAndAnUnusedOneIsReported(): void
    {
        $repository = $this->base()->branch('feature')->write(self::MIGRATION, '<?php // edited')
            ->write('warroom-diff-guards.json', json_encode(['waivers' => [
                ['check' => 'edited-migration', 'path' => self::MIGRATION, 'reason' => 'Never left a developer laptop.'],
                ['check' => 'ignore-growth', 'path' => 'app/Gone.php', 'reason' => 'Stale.'],
            ]], JSON_THROW_ON_ERROR))
            ->commit();

        [$exit, $output] = $this->guard($repository->root, '--base=main');

        self::assertSame(0, $exit, $output);
        self::assertStringContainsString('  waived  edited-migration ' . self::MIGRATION . ': Never left a developer laptop.', $output);
        self::assertStringContainsString('  unused waiver  ignore-growth app/Gone.php matched no finding in this change', $output);
        self::assertStringContainsString("edited-migration: 0 findings over 1 changed migration\n", $output);
        self::assertStringNotContainsString('unused waiver  edited-migration', $output);
    }

    public function testAWaiverForAnotherCheckOrPathExcusesNothing(): void
    {
        $repository = $this->base()->branch('feature')->write(self::MIGRATION, '<?php // edited')
            ->write('warroom-diff-guards.json', json_encode(['waivers' => [
                ['check' => 'ignore-growth', 'path' => self::MIGRATION, 'reason' => 'Wrong check.'],
                ['check' => 'edited-migration', 'path' => 'database/migrations/other.php', 'reason' => 'Wrong path.'],
            ]], JSON_THROW_ON_ERROR))
            ->commit();

        self::assertSame(1, $this->guard($repository->root, '--base=main')[0]);
    }

    public function testAnIgnoreGrowthWaiverNamesTheSilencedPath(): void
    {
        $repository = $this->base()->branch('feature')->write('phpstan.neon', str_replace('count: 3', 'count: 4', self::PARK))
            ->write('warroom-diff-guards.json', json_encode(['waivers' => [
                ['check' => 'ignore-growth', 'path' => 'routes/console.php', 'reason' => 'One more scheduled closure, burned down under its ticket.'],
            ]], JSON_THROW_ON_ERROR))
            ->commit();

        self::assertSame(0, $this->guard($repository->root, '--base=main')[0]);
    }

    public function testAConfiguredMigrationDirectoryReplacesTheDefault(): void
    {
        $repository = $this->base()->branch('feature')->write(self::MIGRATION, '<?php // edited')
            ->write('warroom-diff-guards.json', '{"migrations": ["modules/migrations"]}')
            ->commit();

        [$exit, $output] = $this->guard($repository->root, '--base=main');

        self::assertSame(0, $exit, $output);
        self::assertStringContainsString('edited-migration: compared nothing', $output);
    }

    public function testAnExplicitConfigFileIsRead(): void
    {
        $repository = $this->base()->branch('feature')->write(self::MIGRATION, '<?php // edited')
            ->write('ci/guards.json', '{"migrations": ["modules/migrations"]}')
            ->commit();

        self::assertSame(0, $this->guard($repository->root, '--base=main', '--config=ci/guards.json')[0]);
        self::assertSame(0, $this->guard($repository->root, '--base=main', '--config=' . $repository->root . '/ci/guards.json')[0]);
    }

    public function testHelpPrintsUsageAndPasses(): void
    {
        [$exit, $output] = $this->guard('/nonexistent', '--help');

        self::assertSame(0, $exit);
        self::assertStringStartsWith('Usage: warroom-diff-guards --base=<ref>', $output);
        self::assertSame(0, $this->guard('/nonexistent', '-h')[0]);
    }

    public function testEveryEntryOfARenameHeavyDiffIsParsed(): void
    {
        $repository = $this->base();
        $repository->write('database/migrations/2026_01_02_000000_create_teams_table.php', '<?php // teams')->commit();
        $repository->branch('feature')
            ->move('database/migrations/2026_01_02_000000_create_teams_table.php', 'database/migrations/2026_09_02_000000_create_teams_table.php')
            ->write(self::MIGRATION, '<?php // edited')
            ->write('database/migrations/2026_09_03_000000_create_tags_table.php', '<?php // new')
            ->write('zz/after.php', '<?php')
            ->commit();

        [$exit, $output] = $this->guard($repository->root, '--base=main');

        self::assertSame(1, $exit, $output);
        self::assertStringContainsString("edited-migration: 2 findings over 3 changed migrations\n", $output);
        self::assertStringContainsString('over 4 changed paths', $output);
    }

    public function testTheVerdictNamesTheMergeBaseItCompared(): void
    {
        $repository = $this->base();
        $fork = mb_substr(mb_trim($repository->git('rev-parse', 'HEAD')), 0, 12);
        $repository->branch('feature')->write('app/Other.php', '<?php')->commit();

        self::assertStringContainsString("merge base {$fork} of main", $this->guard($repository->root, '--base=main')[1]);
    }

    public function testAGrowingEntryAfterAnUnchangedOneIsFound(): void
    {
        $two = self::PARK . "\n        -\n            identifier: z.z\n            count: 1\n            path: z.php\n";
        $repository = $this->base();
        $repository->write('phpstan.neon', $two)->commit();
        $repository->branch('feature')->write('phpstan.neon', str_replace('count: 1', 'count: 2', $two))->commit();

        [$exit, $output] = $this->guard($repository->root, '--base=main');

        self::assertSame(1, $exit, $output);
        self::assertStringContainsString('Ignored error z.z @ z.php grew from count 1 to 2.', $output);
    }

    public function testAConfigWithoutPathKeysKeepsEveryDefaultFile(): void
    {
        $repository = $this->base()->branch('feature')
            ->write('phpstan.neon.dist', self::PARK)
            ->write('warroom-diff-guards.json', '{"waivers": []}')
            ->commit();

        [$exit, $output] = $this->guard($repository->root, '--base=main');

        self::assertSame(1, $exit, $output);
        self::assertStringContainsString('grew from count 3 to 6', $output);
    }

    public function testEveryConfiguredMigrationDirectoryIsRead(): void
    {
        $repository = $this->base();
        $repository->write('modules/billing/migrations/x.php', '<?php')->commit();
        $repository->branch('feature')
            ->write('modules/billing/migrations/x.php', '<?php // edited')
            ->write('warroom-diff-guards.json', '{"migrations": ["database/migrations", "modules/billing/migrations"]}')
            ->commit();

        [$exit, $output] = $this->guard($repository->root, '--base=main');

        self::assertSame(1, $exit, $output);
        self::assertStringContainsString('Migration modules/billing/migrations/x.php existed at the base', $output);
    }

    private function base(): GitRepository
    {
        return (new GitRepository)->write(self::MIGRATION, '<?php // users')->write('phpstan.neon', self::PARK)->write('app/Model.php', '<?php')->commit('base');
    }

    /**
     * @return array{int, string}
     */
    private function guard(string $directory, string ...$arguments): array
    {
        $output = fopen('php://memory', 'w+');
        self::assertIsResource($output);

        $exit = (new Application)->run(array_values($arguments), $directory, $output);
        rewind($output);

        return [$exit, (string) stream_get_contents($output)];
    }
}
