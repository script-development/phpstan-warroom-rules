<?php

declare(strict_types = 1);

namespace ScriptDevelopment\PhpstanWarroomRules\DiffGuards;

use function count;
use function is_resource;

/**
 * The repository as the project directory sees it.
 *
 * Every call is an argument array handed to `proc_open`, never a shell string. Paths in
 * and out are relative to the project directory (`--relative`, `<rev>:./<path>`), so a
 * project in `backend/` of a monorepo reads its own files; `prefix()` turns them back
 * into repository paths for annotations.
 */
final readonly class Git
{
    public function __construct(
        private string $directory,
    ) {}

    /**
     * The project's path inside the repository, with a trailing slash; empty at the top level.
     */
    public function prefix(): string
    {
        [$status, $out] = $this->run(['rev-parse', '--is-inside-work-tree', '--show-prefix']);

        if ($status !== 0 || !str_starts_with($out, 'true')) {
            throw CannotRun::missing("{$this->directory} is not inside a git work tree.");
        }

        return mb_trim(mb_substr($out, 4));
    }

    /**
     * Where HEAD forked from `$ref`: the commit every comparison is made against.
     */
    public function forkPoint(string $ref): string
    {
        [$status] = $this->run(['rev-parse', '--verify', '--quiet', '--end-of-options', $ref . '^{commit}']);

        if ($status !== 0) {
            throw CannotRun::missing("Base '{$ref}' does not resolve to a commit here. Fetch it first.");
        }

        [$status, $out] = $this->run(['merge-base', '--end-of-options', $ref, 'HEAD']);

        if ($status !== 0) {
            throw CannotRun::missing("'{$ref}' and HEAD share no merge base. A shallow clone needs fetch-depth: 0.");
        }

        return mb_trim($out);
    }

    /**
     * Every path the change touched under the project directory, renames detected.
     *
     * @return list<ChangedFile>
     */
    public function changes(string $base): array
    {
        [$status, $out, $err] = $this->run(['diff', '--name-status', '-z', '-M', '--no-ext-diff', '--relative', $base, 'HEAD', '--']);

        if ($status !== 0) {
            throw CannotRun::missing("git diff against {$base} failed: " . mb_trim($err));
        }

        $fields = explode("\0", mb_rtrim($out, "\0"));
        $changes = [];

        for ($index = 0; $index < count($fields) - 1; $index += 2) {
            $status = mb_substr($fields[$index], 0, 1);

            if ($status === 'R' || $status === 'C') {
                $changes[] = new ChangedFile($status, $fields[$index + 2], $fields[$index + 1]);
                $index++;

                continue;
            }

            $changes[] = new ChangedFile($status, $fields[$index + 1]);
        }

        return $changes;
    }

    /**
     * A file's contents at a revision, or null when the revision does not carry it.
     */
    public function read(string $revision, string $path): ?string
    {
        [$status, $out] = $this->run(['ls-tree', '--name-only', $revision, '--', $path]);

        if ($status !== 0) {
            throw CannotRun::missing("Cannot list {$path} at {$revision}.");
        }

        if ($out === '') {
            return null;
        }

        [$status, $contents] = $this->run(['cat-file', 'blob', $revision . ':./' . $path]);

        if ($status !== 0) {
            throw CannotRun::missing("Cannot read {$path} at {$revision}.");
        }

        return $contents;
    }

    /**
     * @param list<string> $arguments
     *
     * @return array{int, string, string}
     */
    private function run(array $arguments): array
    {
        $pipes = [];
        $process = proc_open(['git', ...$arguments], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $this->directory);

        if (!is_resource($process)) {
            throw CannotRun::missing('git could not be started.');
        }

        $out = (string) stream_get_contents($pipes[1]);
        $err = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return [proc_close($process), $out, $err];
    }
}
