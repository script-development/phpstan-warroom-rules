<?php

declare(strict_types = 1);

namespace ScriptDevelopment\PhpstanWarroomRules\DiffGuards;

use Nette\Neon\Exception;
use Nette\Neon\Neon;

use function array_key_exists;
use function is_array;
use function is_int;
use function is_string;

/**
 * The `parameters.ignoreErrors` of one NEON file, folded to a count per key.
 *
 * The key is everything that decides which errors an entry silences: the identifier, the
 * message pattern(s) and the path(s). An entry that keeps its identifier, path and count
 * but swaps its message silences a different error, so it is a new key. The price: a
 * PHPStan upgrade that rewords messages in a regenerated baseline reads as new entries.
 * A count of null is an uncounted entry, which silences without limit. `includes` lists
 * the files this one includes, as written.
 */
final readonly class IgnoreEntries
{
    /**
     * @param array<string, ?int>   $counts   key => summed count, null when any entry is uncounted
     * @param array<string, string> $subjects key => the path(s) it silences, empty for every path
     * @param list<string>          $includes
     */
    private function __construct(
        public array $counts,
        public array $subjects,
        public string $contents = '',
        public array $includes = [],
    ) {}

    public static function parse(string $file, string $contents): self
    {
        try {
            $decoded = Neon::decode($contents);
        } catch (Exception $exception) {
            throw CannotRun::invalid("{$file} does not parse as NEON: {$exception->getMessage()}");
        }

        $decoded = is_array($decoded) ? $decoded : [];
        $includes = $decoded['includes'] ?? [];

        if (!is_array($includes) || array_filter($includes, is_string(...)) !== $includes) {
            throw CannotRun::invalid("{$file}: includes is not a list of paths.");
        }

        $parameters = $decoded['parameters'] ?? [];
        $entries = is_array($parameters) ? $parameters['ignoreErrors'] ?? $parameters['ignoreErrors!'] ?? [] : null;

        if (!is_array($entries)) {
            throw CannotRun::invalid("{$file}: parameters.ignoreErrors is not a list.");
        }

        $counts = [];
        $subjects = [];

        foreach ($entries as $entry) {
            [$what, $subject, $count] = self::entry($file, $entry);
            $key = $what . ' @ ' . ($subject === '' ? '(any path)' : $subject);
            $subjects[$key] = $subject;
            $counts[$key] = self::sum($counts, $key, $count);
        }

        return new self($counts, $subjects, $contents, array_values($includes));
    }

    /**
     * Several files folded into one, as PHPStan folds every included file into one list.
     *
     * @param array<self> $files
     */
    public static function merge(array $files): self
    {
        $counts = [];
        $subjects = [];

        foreach ($files as $file) {
            foreach ($file->counts as $key => $count) {
                $counts[$key] = self::sum($counts, $key, $count);
                $subjects[$key] = $file->subjects[$key];
            }
        }

        return new self($counts, $subjects);
    }

    /**
     * @param array<string, ?int> $counts
     */
    private static function sum(array $counts, string $key, ?int $count): ?int
    {
        return match (true) {
            !array_key_exists($key, $counts) => $count,
            $counts[$key] === null, $count === null => null,
            default => $counts[$key] + $count,
        };
    }

    /**
     * @return array{string, string, ?int} what it silences, where, how many times
     */
    private static function entry(string $file, mixed $entry): array
    {
        if (is_string($entry)) {
            return [$entry, '', null];
        }

        if (!is_array($entry)) {
            throw CannotRun::invalid("{$file}: an ignoreErrors entry is neither a pattern nor a map.");
        }

        $paths = $entry['paths'] ?? (isset($entry['path']) ? [$entry['path']] : []);
        $count = $entry['count'] ?? null;

        if (!is_array($paths) || $count !== null && !is_int($count)) {
            throw CannotRun::invalid("{$file}: an ignoreErrors entry has a malformed path or count.");
        }

        $paths = array_values(array_filter($paths, is_string(...)));
        sort($paths);

        return [self::what($entry), implode(', ', $paths), $count];
    }

    /**
     * @param array<mixed> $entry
     */
    private static function what(array $entry): string
    {
        $message = $entry['message'] ?? $entry['rawMessage'] ?? $entry['messages'] ?? $entry['rawMessages'] ?? [];
        $parts = array_values(array_filter(is_array($message) ? $message : [$message], is_string(...)));
        sort($parts);

        if (is_string($entry['identifier'] ?? null)) {
            array_unshift($parts, $entry['identifier']);
        }

        return implode(' | ', $parts);
    }
}
