<?php

declare(strict_types = 1);

namespace App\Models\Audit;

use Illuminate\Database\Eloquent\Factories\HasFactory;

/**
 * A trait that itself composes HasFactory. A model using this trait reaches
 * HasFactory only through a trait-of-a-trait — the recursive leg of the
 * transitive walk (`getTraits(true)`). The trait node is a `Trait_`, not a
 * `Class_`, so the rule skips it directly.
 */
trait ComposedFactoryTrait
{
    use HasFactory;
}
