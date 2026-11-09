<?php

declare(strict_types = 1);

namespace ScriptDevelopment\PhpstanWarroomRules\DiffGuards;

/**
 * One reason the change fails a check.
 *
 * `file` and `line` place the annotation (project-relative); `subject` is the path a
 * waiver names to excuse it, which for an ignore entry is the source path it silences.
 */
final readonly class Finding
{
    public function __construct(
        public string $check,
        public string $file,
        public int $line,
        public string $message,
        public string $subject,
    ) {}
}
