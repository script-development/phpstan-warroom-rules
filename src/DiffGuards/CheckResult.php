<?php

declare(strict_types = 1);

namespace ScriptDevelopment\PhpstanWarroomRules\DiffGuards;

/**
 * What one check found, and over how much it looked.
 *
 * `compared` is the denominator: the number of changed files of the check's kind it read.
 * A result that compared nothing says so in its summary rather than reading as clean.
 */
final readonly class CheckResult
{
    /**
     * @param list<Finding> $findings
     */
    public function __construct(
        public string $check,
        public array $findings,
        public int $compared,
        public string $unit,
    ) {}
}
