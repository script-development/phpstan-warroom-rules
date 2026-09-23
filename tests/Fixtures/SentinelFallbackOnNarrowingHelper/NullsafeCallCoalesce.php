<?php

declare(strict_types = 1);

namespace App\Imports;

use App\Support\LeafReader;

final class NullsafeCallCoalesce
{
    public function __construct(
        private ?LeafReader $reader,
    ) {}

    public function gender(mixed $leaf): string
    {
        // `?->` on a NULLABLE receiver. Pins that the NullsafeMethodCall arm is
        // live: PHPStan analyses the left operand of `??` in isset-ish context,
        // so `$this->reader` is already narrowed to `LeafReader` here and the
        // method resolves without the rule stripping null itself. Fires.
        return $this->reader?->text($leaf) ?? '';
    }
}
