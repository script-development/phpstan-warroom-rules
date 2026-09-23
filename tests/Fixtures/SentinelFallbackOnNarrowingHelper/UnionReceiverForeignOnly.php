<?php

declare(strict_types = 1);

namespace App\Imports;

use Acme\Vendor\BareReader;
use Acme\Vendor\ForeignReader;
use Acme\Vendor\OtherForeignReader;
use App\Support\LeafReader;

final class UnionReceiverForeignOnly
{
    public function bothForeign(ForeignReader|OtherForeignReader $reader, mixed $leaf): string
    {
        return $reader->text($leaf) ?? '';
    }

    // The first-party branch has the method but it is not a narrowing helper
    // (`label()` takes a `string`), so no branch qualifies.
    public function firstPartyBranchNotNarrowing(ForeignReader|LeafReader $reader, string $leaf): string
    {
        return $reader->label($leaf) ?? '';
    }

    public function noBranchHasTheMethod(BareReader|ForeignReader $reader): string
    {
        return $reader->other() ?? '';
    }
}
