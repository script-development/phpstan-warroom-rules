<?php

declare(strict_types = 1);

namespace ScriptDevelopment\PhpstanWarroomRules\DiffGuards;

use const PATHINFO_EXTENSION;

use function array_key_exists;
use function count;
use function dirname;
use function in_array;

/**
 * An error suppressed at HEAD that was not suppressed at the fork point.
 *
 * What is compared is what PHPStan loads: per root config, the root plus every file it
 * `includes`, recursively, folded into one list per revision as PHPStan folds them. The
 * root is each configured `phpstanConfigs` entry, or else the first file PHPStan itself
 * discovers in the project directory. An entry moving between two files loaded together
 * nets to nothing; an entry moving from a file nothing loads into a loaded one is growth.
 * A park is a counted entry that may only shrink (war-room `templates/phpstan.neon`,
 * § Ignored errors): a new entry, a raised count or a removed count each fail.
 *
 * An include that does not resolve to a file in the repository at that revision (a
 * `vendor/` or `phar://` path, `%rootDir%`) is not read at either revision. Nothing is
 * compared unless the change touched a file loaded at one of the two revisions.
 */
final readonly class IgnoreGrowthCheck
{
    public const string NAME = 'ignore-growth';

    /** The names PHPStan probes, in its order, when no `-c` is given. */
    public const array DISCOVERED_CONFIGS = ['.phpstan.neon', 'phpstan.neon', '.phpstan.neon.dist', 'phpstan.neon.dist', '.phpstan.dist.neon', 'phpstan.dist.neon'];

    public function __construct(
        private Git $git,
    ) {}

    /**
     * @param list<ChangedFile> $changes
     * @param list<string>      $roots   empty to discover the root as PHPStan does
     */
    public function run(string $base, array $changes, array $roots): CheckResult
    {
        $changed = [];

        foreach ($changes as $change) {
            $changed[] = $change->path;
            $changed[] = $change->basePath();
        }

        $compared = [];
        $findings = [];

        foreach ($roots === [] ? [null] : $roots as $root) {
            $before = $this->loaded($base, $root);
            $head = $this->loaded('HEAD', $root);
            $touched = array_intersect(array_keys($before + $head), $changed);

            if ($touched === []) {
                continue;
            }

            array_push($compared, ...$touched);

            foreach (self::growth(IgnoreEntries::merge($before), $head) as $finding) {
                $findings[serialize($finding)] = $finding;
            }
        }

        return new CheckResult(self::NAME, array_values($findings), count(array_unique($compared)), 'changed PHPStan config file');
    }

    /**
     * @param array<string, IgnoreEntries> $head file => entries, in load order
     *
     * @return list<Finding>
     */
    private static function growth(IgnoreEntries $before, array $head): array
    {
        $after = IgnoreEntries::merge($head);
        $findings = [];

        foreach ($after->counts as $key => $count) {
            $message = self::message($key, array_key_exists($key, $before->counts) ? [$before->counts[$key]] : [], $count);

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

        return $findings;
    }

    /**
     * Every file PHPStan loads from this root at a revision: the root, then its includes depth-first.
     *
     * @return array<string, IgnoreEntries> file => its entries
     */
    private function loaded(string $revision, ?string $root): array
    {
        if ($root === null) {
            foreach (self::DISCOVERED_CONFIGS as $candidate) {
                if ($this->git->read($revision, $candidate) !== null) {
                    $root = $candidate;

                    break;
                }
            }
        }

        $loaded = [];

        if ($root !== null) {
            $this->load($revision, $root, $loaded);
        }

        return $loaded;
    }

    /**
     * @param array<string, IgnoreEntries> $loaded
     */
    private function load(string $revision, string $file, array &$loaded): void
    {
        if (array_key_exists($file, $loaded)) {
            return;
        }

        $contents = $this->git->read($revision, $file);

        if ($contents === null) {
            return;
        }

        $entries = IgnoreEntries::parse($file, $contents);
        $loaded[$file] = $entries;

        foreach ($entries->includes as $include) {
            $path = self::resolve($include, $file);

            if ($path === null) {
                continue;
            }

            if (!in_array(pathinfo($path, PATHINFO_EXTENSION), ['neon', 'dist'], true)) {
                throw CannotRun::invalid("{$file} includes {$include}, which is not NEON; its ignores cannot be compared.");
            }

            $this->load($revision, $path, $loaded);
        }
    }

    /**
     * An include as a project-relative path, or null when it points outside the repository.
     */
    private static function resolve(string $include, string $from): ?string
    {
        $fromProject = str_starts_with($include, '%currentWorkingDirectory%/');
        $include = $fromProject ? mb_substr($include, 26) : $include;

        if (str_contains($include, '%') || str_contains($include, '://') || str_starts_with($include, '/')) {
            return null;
        }

        $directory = $fromProject ? '.' : dirname($from);
        $segments = [];

        foreach (explode('/', ($directory === '.' ? '' : $directory . '/') . $include) as $segment) {
            if ($segment === '..') {
                if ($segments === [] || end($segments) === '..') {
                    $segments[] = '..';
                } else {
                    array_pop($segments);
                }
            } elseif ($segment !== '.' && $segment !== '') {
                $segments[] = $segment;
            }
        }

        return implode('/', $segments);
    }

    /**
     * @param array{}|array{?int} $before empty when the key did not exist at the base
     */
    private static function message(string $key, array $before, ?int $after): ?string
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
