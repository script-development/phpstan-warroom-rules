<?php

declare(strict_types = 1);

namespace ScriptDevelopment\PhpstanWarroomRules\Support;

use function array_map;
use function mb_rtrim;
use function mb_strtolower;
use function str_starts_with;

/**
 * The one namespace-prefix test the rules share (WR-1291). A prefix names a
 * namespace and everything under it, matched on the `\` boundary: `App\Actions`
 * contains `App\Actions` and `App\Actions\Billing`, never `App\ActionsLegacy`.
 * PHP resolves namespace names case-insensitively, so the test folds case:
 * `app\actions\Billing` is inside `App\Actions`.
 */
final class NamespacePrefix
{
    /** Where every consumer keeps its Actions — see the package CLAUDE.md conventions. */
    public const string ACTIONS = 'App\Actions';

    /**
     * A configured `App\Http\Controllers\` and `App\Http\Controllers` name the
     * same namespace; trimming once keeps the boundary test from treating the
     * trailing form as a second spelling that never matches.
     *
     * @param list<string> $prefixes
     *
     * @return list<string>
     */
    public static function normalise(array $prefixes): array
    {
        return array_map(static fn(string $prefix): string => mb_rtrim($prefix, '\\'), $prefixes);
    }

    public static function contains(string $prefix, string $namespace): bool
    {
        $prefix = mb_strtolower($prefix);
        $namespace = mb_strtolower($namespace);

        return $namespace === $prefix || str_starts_with($namespace, $prefix . '\\');
    }

    /**
     * @param list<string> $prefixes normalised prefixes
     */
    public static function containsAny(array $prefixes, string $namespace): bool
    {
        foreach ($prefixes as $prefix) {
            if (self::contains($prefix, $namespace)) {
                return true;
            }
        }

        return false;
    }
}
