<?php

declare(strict_types = 1);

namespace ScriptDevelopment\PhpstanWarroomRules\DiffGuards;

/**
 * A finding the project accepts, named by check and subject path, with the reason why.
 */
final readonly class Waiver
{
    public function __construct(
        public string $check,
        public string $path,
        public string $reason,
    ) {}

    public function excuses(Finding $finding): bool
    {
        return $finding->check === $this->check && $finding->subject === $this->path;
    }
}
