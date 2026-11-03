<?php

declare(strict_types = 1);

namespace ScriptDevelopment\PhpstanWarroomRules\DiffGuards;

use RuntimeException;

/**
 * The comparison could not be made, so no verdict exists: exit 2, never a pass.
 *
 * `MISSING` names an input that is absent (no repository, no base, no merge base, an
 * unreadable file); `INVALID` names a configuration the tool refuses to trust.
 */
final class CannotRun extends RuntimeException
{
    public const string MISSING = 'MISSING';

    public const string INVALID = 'INVALID';

    public function __construct(
        public readonly string $kind,
        string $message,
    ) {
        parent::__construct($message);
    }

    public static function missing(string $message): self
    {
        return new self(self::MISSING, $message);
    }

    public static function invalid(string $message): self
    {
        return new self(self::INVALID, $message);
    }
}
