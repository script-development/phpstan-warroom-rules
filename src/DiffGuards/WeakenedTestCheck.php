<?php

declare(strict_types = 1);

namespace ScriptDevelopment\PhpstanWarroomRules\DiffGuards;

/**
 * A test the change made check less: skipped, given an assertion that cannot fail,
 * stripped of every assertion, or deleted with nothing in its place.
 *
 * Each changed PHP file under a test directory is read at both revisions (a renamed file
 * at its old path) and its tests compared by name. A skip or a trivial assertion is a
 * finding only where it is new: a test skipped at the base stays silent, and a brand-new
 * skipped test removed nothing. A removed test is replaced by a same-named test anywhere
 * in the changed test files, by a new test with an identical body, or by a new test whose
 * assertions that can fail number at least as many as the removed test's; each new test
 * replaces one removed test at most.
 */
final readonly class WeakenedTestCheck
{
    public const string NAME = 'weakened-test';

    public function __construct(
        private Git $git,
    ) {}

    /**
     * @param list<ChangedFile> $changes
     * @param list<string>      $directories
     */
    public function run(string $base, array $changes, array $directories): CheckResult
    {
        $findings = [];
        $compared = 0;
        $removed = [];
        $added = [];
        $present = [];

        foreach ($changes as $change) {
            $basePath = $change->basePath();
            $headPath = $change->status === 'D' ? null : $change->path;

            if (!self::isTest($headPath, $directories) && !self::isTest($basePath, $directories)) {
                continue;
            }

            $compared++;
            $before = $basePath === null ? [] : TestInventory::of($basePath, $this->contents($base, $basePath));
            $after = $headPath === null ? [] : TestInventory::of($headPath, $this->contents('HEAD', $headPath));
            $file = $headPath ?? (string) $basePath;

            foreach ($after as $name => $test) {
                $present[$name] = true;
                $old = $before[$name] ?? null;
                $message = self::weakened($name, $old, $test);

                if ($message !== null) {
                    $findings[] = new Finding(self::NAME, $file, $test->line, $message, $file);
                }

                if ($old === null) {
                    $added[] = $test;
                }
            }

            foreach ($before as $name => $test) {
                if (!isset($after[$name])) {
                    $removed[] = [$file, $name, $test];
                }
            }
        }

        foreach ($removed as [$file, $name, $test]) {
            if (isset($present[$name])) {
                continue;
            }

            $replacement = self::replacement($test, $added);

            if ($replacement !== null) {
                unset($added[$replacement]);

                continue;
            }

            $findings[] = new Finding(self::NAME, $file, 1, "Test {$name} was deleted, and no test in this change replaces it.", $file);
        }

        return new CheckResult(self::NAME, $findings, $compared, 'changed test file');
    }

    /**
     * @param list<string> $directories
     */
    private static function isTest(?string $path, array $directories): bool
    {
        if ($path === null || !str_ends_with($path, '.php')) {
            return false;
        }

        foreach ($directories as $directory) {
            if (str_starts_with($path, $directory . '/')) {
                return true;
            }
        }

        return false;
    }

    private static function weakened(string $name, ?TestBody $old, TestBody $new): ?string
    {
        if ($old !== null && $new->skipped && !$old->skipped) {
            return "Test {$name} is now skipped.";
        }

        if ($new->trivial > ($old === null ? 0 : $old->trivial)) {
            return "Test {$name} gained an assertion that cannot fail.";
        }

        if ($old !== null && !$new->skipped && $old->assertions > 0 && $new->assertions === 0) {
            return "Test {$name} made {$old->assertions} assertion(s) at the base and makes none now.";
        }

        return null;
    }

    /**
     * @param array<int, TestBody> $added
     */
    private static function replacement(TestBody $removed, array $added): ?int
    {
        foreach ($added as $index => $candidate) {
            if ($candidate->hash === $removed->hash) {
                return $index;
            }
        }

        foreach ($added as $index => $candidate) {
            if ($candidate->checks() >= $removed->checks()) {
                return $index;
            }
        }

        return null;
    }

    private function contents(string $revision, string $path): string
    {
        return $this->git->read($revision, $path) ?? throw CannotRun::missing("{$path} is listed as changed but is absent at {$revision}.");
    }
}
