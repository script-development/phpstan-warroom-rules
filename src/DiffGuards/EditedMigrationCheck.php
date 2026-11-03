<?php

declare(strict_types = 1);

namespace ScriptDevelopment\PhpstanWarroomRules\DiffGuards;

/**
 * A migration that existed at the fork point and the change modified, renamed or deleted.
 *
 * Laravel records a migration as run by its file name and never runs it again, so an edit
 * never reaches a database where it already ran; a rename runs it a second time there,
 * even when the content is unchanged; a deletion breaks `migrate:rollback` and
 * `migrate:reset` wherever it ran. A migration added by this change is the change's own
 * to edit, and git reports it as added however often it was rewritten.
 */
final readonly class EditedMigrationCheck
{
    public const string NAME = 'edited-migration';

    /**
     * @param list<ChangedFile> $changes
     * @param list<string>      $directories
     */
    public function run(array $changes, array $directories): CheckResult
    {
        $findings = [];
        $compared = 0;

        foreach ($changes as $change) {
            $base = $change->basePath();
            $touched = self::isMigration($change->path, $directories) || $base !== null && self::isMigration($base, $directories);

            if (!$touched) {
                continue;
            }

            $compared++;

            if ($base === null || !self::isMigration($base, $directories)) {
                continue;
            }

            $findings[] = new Finding(self::NAME, $change->status === 'D' ? $base : $change->path, 1, self::message($change, $base), $base);
        }

        return new CheckResult(self::NAME, $findings, $compared, 'changed migration');
    }

    /**
     * @param list<string> $directories
     */
    private static function isMigration(string $path, array $directories): bool
    {
        if (!str_ends_with($path, '.php')) {
            return false;
        }

        foreach ($directories as $directory) {
            if (str_starts_with($path, $directory . '/')) {
                return true;
            }
        }

        return false;
    }

    private static function message(ChangedFile $change, string $base): string
    {
        return match ($change->status) {
            'D' => "Migration {$base} existed at the base and this change deletes it; rollback and reset break wherever it ran.",
            'R' => "Migration {$base} was renamed to {$change->path}; wherever the old name ran, it runs again. Add a new migration instead.",
            default => "Migration {$base} existed at the base and this change edits it; wherever it already ran, the edit is never applied. Add a new migration instead.",
        };
    }
}
