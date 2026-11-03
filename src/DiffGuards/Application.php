<?php

declare(strict_types = 1);

namespace ScriptDevelopment\PhpstanWarroomRules\DiffGuards;

use function count;
use function in_array;

/**
 * `warroom-diff-guards`: compare the change on HEAD with where it forked from a base.
 *
 * Exit 0 when every check passed, 1 on any unwaived finding, 2 when no comparison could
 * be made (a MISSING input or an INVALID config). Every run states, per check, how many
 * files it compared, so a check that compared nothing reads as such and not as clean.
 */
final readonly class Application
{
    public const int PASSED = 0;

    public const int FAILED = 1;

    public const int CANNOT_RUN = 2;

    private const string USAGE = <<<'EOF'
        Usage: warroom-diff-guards --base=<ref> [--format=human|github] [--config=<file>]

        Compares HEAD with `git merge-base <ref> HEAD` and fails on an edited migration,
        a grown PHPStan ignore or a weakened test. Run it from the composer project directory.

        Exit codes: 0 passed, 1 findings, 2 could not run (MISSING or INVALID).

        EOF;

    /**
     * @param list<string> $arguments argv without the program name
     * @param resource     $output
     */
    public function run(array $arguments, string $directory, $output): int
    {
        $format = 'human';

        try {
            $options = self::options($arguments);

            if (isset($options['help'])) {
                fwrite($output, self::USAGE);

                return self::PASSED;
            }

            $format = $options['format'] ?? 'human';

            if (!in_array($format, ['human', 'github'], true)) {
                throw CannotRun::invalid("Unknown --format '{$format}'; use human or github.");
            }

            $base = $options['base'] ?? '';

            if ($base === '') {
                throw CannotRun::missing('--base=<ref> is required: without a base there is nothing to compare, and no comparison is never a pass.');
            }

            $reporter = new Reporter($format, $output);

            return $this->compare($directory, $base, $options['config'] ?? null, $reporter);
        } catch (CannotRun $exception) {
            (new Reporter($format, $output))->cannotRun($exception);

            return self::CANNOT_RUN;
        }
    }

    private function compare(string $directory, string $base, ?string $configPath, Reporter $reporter): int
    {
        if (!is_dir($directory)) {
            throw CannotRun::missing("{$directory} is not a directory.");
        }

        $config = Config::load($directory, $configPath);
        $git = new Git($directory);
        $prefix = $git->prefix();
        $fork = $git->forkPoint($base);
        $changes = $git->changes($fork);

        $results = [
            (new EditedMigrationCheck)->run($changes, $config->migrations),
            (new IgnoreGrowthCheck($git))->run($fork, $changes, $config->phpstanConfigs),
            (new WeakenedTestCheck($git))->run($fork, $changes, $config->tests),
        ];

        $failed = 0;
        $used = [];

        foreach ($results as $result) {
            $kept = [];

            foreach ($result->findings as $finding) {
                $waiver = self::waiverFor($finding, $config->waivers);

                if ($waiver === null) {
                    $kept[] = $finding;

                    continue;
                }

                $used[spl_object_id($waiver)] = true;
                $reporter->waived($finding, $waiver);
            }

            $failed += count($kept);
            $reporter->result($result, $kept, $prefix);
        }

        foreach ($config->waivers as $waiver) {
            if (!isset($used[spl_object_id($waiver)])) {
                $reporter->unusedWaiver($waiver);
            }
        }

        $reporter->verdict($base, $fork, count($changes), $failed);

        return $failed === 0 ? self::PASSED : self::FAILED;
    }

    /**
     * @param list<Waiver> $waivers
     */
    private static function waiverFor(Finding $finding, array $waivers): ?Waiver
    {
        foreach ($waivers as $waiver) {
            if ($waiver->excuses($finding)) {
                return $waiver;
            }
        }

        return null;
    }

    /**
     * @param list<string> $arguments
     *
     * @return array<string, string>
     */
    private static function options(array $arguments): array
    {
        $options = [];

        for ($index = 0; $index < count($arguments); $index++) {
            $argument = $arguments[$index];

            if ($argument === '--help' || $argument === '-h') {
                $options['help'] = '';

                continue;
            }

            if (preg_match('/^--(base|format|config)(?:=(.*))?$/', $argument, $match) !== 1) {
                throw CannotRun::invalid("Unknown argument '{$argument}'.\n" . self::USAGE);
            }

            $value = $match[2] ?? $arguments[++$index] ?? null;

            if ($value === null) {
                throw CannotRun::invalid("--{$match[1]} needs a value.");
            }

            $options[$match[1]] = $value;
        }

        return $options;
    }
}
