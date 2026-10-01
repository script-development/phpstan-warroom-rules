<?php

declare(strict_types = 1);

namespace App\Imports;

use App\Support\LeafReader;

final class SentinelShapes
{
    public function __construct(
        private LeafReader $reader,
    ) {}

    public function fromClassConstant(mixed $leaf): string
    {
        // A class constant is still a plausible-looking value. Fires.
        return $this->reader->text($leaf) ?? LeafReader::UNKNOWN;
    }

    /**
     * @return list<string>
     */
    public function fromEmptyArray(mixed $leaf): array
    {
        // `[]` reads as "nothing found" rather than "input unreadable". Fires.
        return $this->reader->scalar($leaf) ?? [];
    }

    public function fromFalse(mixed $leaf): bool
    {
        // `false` is a decided answer; null was an undecided one. Fires.
        return $this->reader->flag($leaf) ?? false;
    }
}
