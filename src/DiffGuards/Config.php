<?php

declare(strict_types = 1);

namespace ScriptDevelopment\PhpstanWarroomRules\DiffGuards;

use const JSON_THROW_ON_ERROR;

use JsonException;

use function array_key_exists;
use function in_array;
use function is_array;
use function is_string;

/**
 * The project's `warroom-diff-guards.json`, or the defaults when it has none.
 *
 * Every key is optional; an unknown key, a wrong type or a waiver without a reason makes
 * the whole file INVALID (exit 2) rather than half-read, because a typo in a waiver key
 * must not read as "no waivers".
 */
final readonly class Config
{
    public const string FILE = 'warroom-diff-guards.json';

    public const array CHECKS = [EditedMigrationCheck::NAME, IgnoreGrowthCheck::NAME, WeakenedTestCheck::NAME];

    public const array DEFAULT_MIGRATIONS = ['database/migrations'];

    public const array DEFAULT_TESTS = ['tests'];

    /**
     * @param list<string> $migrations
     * @param list<string> $phpstanConfigs root configs, as passed to `phpstan -c`; empty to discover one as PHPStan does
     * @param list<Waiver> $waivers
     * @param list<string> $tests
     */
    public function __construct(
        public array $migrations = self::DEFAULT_MIGRATIONS,
        public array $phpstanConfigs = [],
        public array $waivers = [],
        public array $tests = self::DEFAULT_TESTS,
    ) {}

    /**
     * @param string $path absolute, or relative to the project directory
     */
    public static function load(string $directory, ?string $path): self
    {
        $explicit = $path !== null;
        $path ??= self::FILE;
        $file = str_starts_with($path, '/') ? $path : $directory . '/' . $path;

        if (!is_file($file)) {
            if ($explicit) {
                throw CannotRun::missing("Config file {$path} does not exist.");
            }

            return new self;
        }

        try {
            $data = json_decode((string) file_get_contents($file), true, 16, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw CannotRun::invalid("{$path} is not valid JSON: {$exception->getMessage()}");
        }

        if (!is_array($data) || array_is_list($data) && $data !== []) {
            throw CannotRun::invalid("{$path} must hold a JSON object.");
        }

        $unknown = array_diff(array_keys($data), ['migrations', 'phpstanConfigs', 'tests', 'waivers']);

        if ($unknown !== []) {
            throw CannotRun::invalid("{$path} has unknown key(s): " . implode(', ', $unknown) . '.');
        }

        return new self(
            self::paths($data, 'migrations', self::DEFAULT_MIGRATIONS, $path),
            self::paths($data, 'phpstanConfigs', [], $path),
            self::waivers($data['waivers'] ?? [], $path),
            self::paths($data, 'tests', self::DEFAULT_TESTS, $path),
        );
    }

    /**
     * @param array<mixed> $data
     * @param list<string> $default
     *
     * @return list<string>
     */
    private static function paths(array $data, string $key, array $default, string $file): array
    {
        if (!array_key_exists($key, $data)) {
            return $default;
        }

        $value = $data[$key];

        if (!is_array($value) || !array_is_list($value) || $value === []) {
            throw CannotRun::invalid("{$file}: '{$key}' must be a non-empty list of paths.");
        }

        $paths = [];

        foreach ($value as $entry) {
            if (!is_string($entry) || mb_trim($entry, '/ ') === '') {
                throw CannotRun::invalid("{$file}: every '{$key}' entry must be a non-empty path.");
            }

            $paths[] = mb_trim($entry, '/ ');
        }

        return $paths;
    }

    /**
     * @return list<Waiver>
     */
    private static function waivers(mixed $value, string $file): array
    {
        if (!is_array($value) || !array_is_list($value)) {
            throw CannotRun::invalid("{$file}: 'waivers' must be a list.");
        }

        $waivers = [];

        foreach ($value as $index => $entry) {
            if (!is_array($entry) || array_diff(array_keys($entry), ['check', 'path', 'reason']) !== []) {
                throw CannotRun::invalid("{$file}: waiver #{$index} must be an object with only check, path and reason.");
            }

            $check = $entry['check'] ?? null;
            $path = $entry['path'] ?? null;
            $reason = $entry['reason'] ?? null;

            if (!in_array($check, self::CHECKS, true)) {
                throw CannotRun::invalid("{$file}: waiver #{$index} names no known check (" . implode(', ', self::CHECKS) . ').');
            }

            if (!is_string($path) || mb_trim($path) === '') {
                throw CannotRun::invalid("{$file}: waiver #{$index} names no path.");
            }

            if (!is_string($reason) || mb_trim($reason) === '') {
                throw CannotRun::invalid("{$file}: waiver #{$index} for {$path} carries no reason. A waiver without a reason is not accepted.");
            }

            $waivers[] = new Waiver($check, mb_trim($path), mb_trim($reason));
        }

        return $waivers;
    }
}
