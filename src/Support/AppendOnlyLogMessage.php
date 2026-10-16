<?php

declare(strict_types = 1);

namespace ScriptDevelopment\PhpstanWarroomRules\Support;

use function sprintf;

/**
 * The one error message `LogRule` and `LogBuilderTruncateRule` share, so the
 * two halves of the append-only doctrine cannot drift apart in what they tell
 * a consumer; both pass their shared identifier in.
 */
final class AppendOnlyLogMessage
{
    /**
     * @param string $method      the method as written at the call site
     * @param string $target      what the call modifies, e.g. a class name or "table 'logs'"
     * @param string $matchedName what the rule matched "log" in: "class" or "table"
     * @param string $identifier  the error identifier a consumer ignores to suppress a false positive
     */
    public static function for(string $method, string $target, string $matchedName, string $identifier): string
    {
        return sprintf(
            'Calling %s() on %s modifies a log. Log and audit records are append-only (ADR-0001): record a new entry instead of changing or removing one. '
            . 'The rule matches "log" anywhere in the %s name; if this is not a log, ignore %s for this path in phpstan.neon.',
            $method,
            $target,
            $matchedName,
            $identifier,
        );
    }
}
