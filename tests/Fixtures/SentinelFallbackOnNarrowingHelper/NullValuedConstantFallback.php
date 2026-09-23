<?php

declare(strict_types = 1);

namespace App\Imports;

use App\Support\LeafReader;

const LEAF_MISSING = null;

final class NullValuedConstantFallback
{
    public function __construct(
        private LeafReader $reader,
    ) {}

    public function fromClassConstant(mixed $leaf): ?string
    {
        // `LeafReader::NONE` is null — `?? null` by name. Silent.
        return $this->reader->text($leaf) ?? LeafReader::NONE;
    }

    public function fromGlobalConstant(mixed $leaf): ?string
    {
        // Same for a null-valued global constant. Silent.
        return $this->reader->text($leaf) ?? LEAF_MISSING;
    }
}
