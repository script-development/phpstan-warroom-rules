<?php

declare(strict_types = 1);

namespace ScriptDevelopment\PhpstanWarroomRules\DiffGuards;

use function array_key_exists;
use function count;
use function in_array;

/**
 * An ignored error the change added, or a counted park whose count it raised.
 *
 * Every configured ignore file is read at both revisions and folded into one list, as
 * PHPStan folds its includes, so an entry moved from `phpstan.neon` into a baseline nets
 * to nothing. A park is a counted entry that may only shrink (war-room
 * `templates/phpstan.neon`, § Ignored errors): a raised count, a new entry, or a counted
 * entry made uncounted each fail. Nothing is compared unless the change touched one of
 * the configured files.
 */
final readonly class IgnoreGrowthCheck
{
    public const string NAME = 'ignore-growth';

    public function __construct(
        private Git $git,
    ) {}

    /**
     * @param list<ChangedFile> $changes
     * @param list<string>      $files
     */
    public function run(string $base, array $changes, array $files): CheckResult
    {
        $changed = [];

        foreach ($changes as $change) {
            foreach ([$change->path, $change->basePath()] as $path) {
                if ($path !== null && in_array($path, $files, true)) {
                    $changed[$path] = true;
                }
            }
        }

        if ($changed === []) {
            return new CheckResult(self::NAME, [], 0, 'changed ignore file');
        }

        $before = IgnoreEntries::merge(array_values($this->read($base, $files)));
        $head = $this->read('HEAD', $files);
        $after = IgnoreEntries::merge(array_values($head));
        $findings = [];

        foreach ($after->counts as $key => $count) {
            $message = self::growth($key, array_key_exists($key, $before->counts) ? [$before->counts[$key]] : [], $count);

            if ($message === null) {
                continue;
            }

            foreach ($head as $file => $entries) {
                if (array_key_exists($key, $entries->counts)) {
                    $subject = $after->subjects[$key];
                    $findings[] = new Finding(self::NAME, $file, self::line($entries->contents, $subject), $message, $subject === '' ? $file : $subject);

                    break;
                }
            }
        }

        return new CheckResult(self::NAME, $findings, count($changed), 'changed ignore file');
    }

    /**
     * @param list<string> $files
     *
     * @return array<string, IgnoreEntries> file => its entries, for the files the revision carries
     */
    private function read(string $revision, array $files): array
    {
        $entries = [];

        foreach ($files as $file) {
            $contents = $this->git->read($revision, $file);

            if ($contents !== null) {
                $entries[$file] = IgnoreEntries::parse($file, $contents);
            }
        }

        return $entries;
    }

    /**
     * @param array{}|array{?int} $before empty when the key did not exist at the base
     */
    private static function growth(string $key, array $before, ?int $after): ?string
    {
        if ($before === []) {
            return "New ignored error: {$key}" . ($after === null ? ', uncounted.' : ", count {$after}.");
        }

        [$was] = $before;

        if ($was === null) {
            return null;
        }

        if ($after === null) {
            return "Ignored error {$key} lost its count ({$was}) and now silences without limit.";
        }

        return $after > $was ? "Ignored error {$key} grew from count {$was} to {$after}. A park may only shrink." : null;
    }

    private static function line(string $contents, string $subject): int
    {
        if ($subject !== '') {
            foreach (explode("\n", $contents) as $index => $line) {
                if (str_contains($line, explode(', ', $subject)[0])) {
                    return $index + 1;
                }
            }
        }

        return 1;
    }
}
