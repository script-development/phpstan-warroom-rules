<?php

declare(strict_types = 1);

namespace App\Models\CredentialCastBypass;

/**
 * A trait that itself uses another trait — `getTraits(true)` flattens the chain,
 * so a cast two hops away still counts.
 */
trait ComposesHashedSecret
{
    use HasHashedSecret;
}
