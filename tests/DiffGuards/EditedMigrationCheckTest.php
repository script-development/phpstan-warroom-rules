<?php

declare(strict_types = 1);

namespace ScriptDevelopment\PhpstanWarroomRules\Tests\DiffGuards;

use PHPUnit\Framework\TestCase;
use ScriptDevelopment\PhpstanWarroomRules\DiffGuards\ChangedFile;
use ScriptDevelopment\PhpstanWarroomRules\DiffGuards\CheckResult;
use ScriptDevelopment\PhpstanWarroomRules\DiffGuards\EditedMigrationCheck;
use ScriptDevelopment\PhpstanWarroomRules\DiffGuards\Finding;

final class EditedMigrationCheckTest extends TestCase
{
    private const string MIGRATION = 'database/migrations/2026_01_01_000000_create_users_table.php';

    public function testAModifiedMigrationThatExistedAtTheBaseIsAFinding(): void
    {
        $result = $this->check(new ChangedFile('M', self::MIGRATION));

        self::assertSame(1, $result->compared);
        self::assertSame([[self::MIGRATION, self::MIGRATION, 1]], self::placed($result->findings));
        self::assertStringContainsString('the edit is never applied', $result->findings[0]->message);
        self::assertSame('edited-migration', $result->findings[0]->check);
    }

    public function testADeletedMigrationIsAFindingOnItsOldPath(): void
    {
        $result = $this->check(new ChangedFile('D', self::MIGRATION));

        self::assertSame([[self::MIGRATION, self::MIGRATION, 1]], self::placed($result->findings));
        self::assertStringContainsString('deletes it', $result->findings[0]->message);
    }

    public function testARenameIsAFindingEvenWithoutAContentChange(): void
    {
        $renamed = 'database/migrations/2026_02_01_000000_create_users_table.php';
        $result = $this->check(new ChangedFile('R', $renamed, self::MIGRATION));

        self::assertSame([[$renamed, self::MIGRATION, 1]], self::placed($result->findings));
        self::assertStringContainsString("was renamed to {$renamed}", $result->findings[0]->message);
    }

    public function testARenameOutOfTheMigrationsDirectoryIsAFinding(): void
    {
        $result = $this->check(new ChangedFile('R', 'database/old/create_users_table.php', self::MIGRATION));

        self::assertCount(1, $result->findings);
        self::assertSame(1, $result->compared);
    }

    public function testATypeChangeIsAFinding(): void
    {
        self::assertCount(1, $this->check(new ChangedFile('T', self::MIGRATION))->findings);
    }

    public function testAnAddedMigrationIsComparedButSilent(): void
    {
        $result = $this->check(new ChangedFile('A', self::MIGRATION));

        self::assertSame([], $result->findings);
        self::assertSame(1, $result->compared);
    }

    public function testAFileMovedIntoTheMigrationsDirectoryIsComparedButSilent(): void
    {
        $result = $this->check(new ChangedFile('R', self::MIGRATION, 'stubs/create_users_table.php'));

        self::assertSame([], $result->findings);
        self::assertSame(1, $result->compared);
    }

    public function testACopiedMigrationIsAnAddition(): void
    {
        $copy = 'database/migrations/2026_03_01_000000_create_users_table.php';

        self::assertSame([], $this->check(new ChangedFile('C', $copy, self::MIGRATION))->findings);
    }

    public function testPathsOutsideTheMigrationDirectoriesAreNotCompared(): void
    {
        $result = $this->check(
            new ChangedFile('M', 'app/Models/User.php'),
            new ChangedFile('M', 'database/migrations-old/2026_01_01_x.php'),
            new ChangedFile('M', 'database/migrations/README.md'),
            new ChangedFile('M', 'database/seeders/UserSeeder.php'),
        );

        self::assertSame([], $result->findings);
        self::assertSame(0, $result->compared);
        self::assertSame('changed migration', $result->unit);
    }

    public function testEveryConfiguredDirectoryIsRead(): void
    {
        $result = (new EditedMigrationCheck)->run(
            [new ChangedFile('M', 'database/migrations/tenant/x.php'), new ChangedFile('M', 'modules/billing/migrations/y.php')],
            ['database/migrations', 'modules/billing/migrations'],
        );

        self::assertCount(2, $result->findings);
        self::assertSame(2, $result->compared);
    }

    public function testEveryChangeIsReadWhateverComesBeforeIt(): void
    {
        $result = $this->check(
            new ChangedFile('M', 'app/Models/User.php'),
            new ChangedFile('A', 'database/migrations/2026_05_01_000000_new.php'),
            new ChangedFile('M', self::MIGRATION),
        );

        self::assertSame(2, $result->compared);
        self::assertSame([[self::MIGRATION, self::MIGRATION, 1]], self::placed($result->findings));
    }

    private function check(ChangedFile ...$changes): CheckResult
    {
        return (new EditedMigrationCheck)->run(array_values($changes), ['database/migrations']);
    }

    /**
     * @param list<Finding> $findings
     *
     * @return list<array{string, string, int}>
     */
    private static function placed(array $findings): array
    {
        return array_map(static fn(Finding $finding): array => [$finding->file, $finding->subject, $finding->line], $findings);
    }
}
