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
 * The key is every field PHPStan's `ignoreErrors` schema matches on: `identifier` and
 * `identifiers`, `message` and `messages`, `rawMessage` and `rawMessages`, `path` and
 * `paths`, each singular folded into its plural as PHPStan expands them. `count` is the
 * quantity, `reportUnmatched` changes nothing that is suppressed. An entry that keeps its identifier, path and count
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

        if (array_key_exists('parameters!', $decoded) || is_array($parameters) && array_key_exists('ignoreErrors!', $parameters)) {
            throw CannotRun::invalid("{$file} replaces included ignoreErrors with a `!` key; this tool compares only merged lists. Drop the `!` or remove the included entries.");
        }

        $entries = is_array($parameters) ? $parameters['ignoreErrors'] ?? [] : null;

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

        $count = $entry['count'] ?? null;

        if (array_key_exists('paths', $entry) && !is_array($entry['paths']) || $count !== null && !is_int($count)) {
            throw CannotRun::invalid("{$file}: an ignoreErrors entry has a malformed path or count.");
        }

        $paths = self::strings($entry, 'path', 'paths');

        return [self::what($entry), implode(', ', $paths), $count];
    }

    /**
     * @param array<mixed> $entry
     */
    private static function what(array $entry): string
    {
        $identifiers = self::strings($entry, 'identifier', 'identifiers');
        $messages = self::strings($entry, 'message', 'messages');
        $raw = array_map(static fn(string $message): string => 'rawMessage ' . $message, self::strings($entry, 'rawMessage', 'rawMessages'));

        return implode(' | ', [...$identifiers, ...$messages, ...$raw]);
    }

    /**
     * The singular and plural spellings of one field, merged and sorted, as PHPStan expands them.
     *
     * @param array<mixed> $entry
     *
     * @return list<string>
     */
    private static function strings(array $entry, string $one, string $many): array
    {
        $values = [$entry[$one] ?? null, ...(is_array($entry[$many] ?? null) ? $entry[$many] : [])];
        $values = array_values(array_filter($values, is_string(...)));
        sort($values);

        return $values;
    }
}
