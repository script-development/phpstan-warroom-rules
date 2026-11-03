<?php

declare(strict_types = 1);

namespace ScriptDevelopment\PhpstanWarroomRules\DiffGuards;

/**
 * One test as `TestInventory` read it.
 *
 * `assertions` includes those made through same-file helpers and the `trivial` ones that
 * cannot fail; `hash` is the printed body, so a renamed but unchanged test is recognised.
 */
final readonly class TestBody
{
    public function __construct(
        public int $line,
        public int $assertions,
        public bool $skipped,
        public int $trivial,
        public string $hash,
    ) {}

    /**
     * Assertions that can fail.
     */
    public function checks(): int
    {
        return $this->assertions - $this->trivial;
    }
}
