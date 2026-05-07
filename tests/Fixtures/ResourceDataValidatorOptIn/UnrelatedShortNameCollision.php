<?php

declare(strict_types = 1);

namespace App\Unrelated;

// A class with the short name `ResourceData` in an unrelated namespace MUST
// NOT be matched as the rule's base class. The detection uses the FQCN, not
// the short name.
abstract class ResourceData {}

final class UnrelatedShortNameCollision extends ResourceData
{
    public const array EAGER_LOAD_COUNT = ['rel'];

    public static function fromAnything(): self
    {
        return new self;
    }
}
