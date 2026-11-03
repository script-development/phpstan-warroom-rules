<?php

declare(strict_types = 1);

namespace ScriptDevelopment\PhpstanWarroomRules\DiffGuards;

/**
 * One path git reports between the fork point and HEAD.
 *
 * `status` is git's one-letter code (`A`, `M`, `D`, `R`, `C`, `T`); `previousPath` is
 * set for a rename or copy only. Both paths are relative to the project directory.
 */
final readonly class ChangedFile
{
    public function __construct(
        public string $status,
        public string $path,
        public ?string $previousPath = null,
    ) {}

    /**
     * The path this file had at the fork point, or null when it did not exist there.
     */
    public function basePath(): ?string
    {
        return match ($this->status) {
            'A', 'C' => null,
            'R' => $this->previousPath,
            default => $this->path,
        };
    }
}
