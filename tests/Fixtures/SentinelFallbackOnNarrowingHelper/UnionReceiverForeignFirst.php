<?php

declare(strict_types = 1);

namespace App\Imports;

use App\Support\LeafReader;
use Application\Support\ImposterReader;
use Vendor\Support\ForeignReader;

final class UnionReceiverForeignFirst
{
    public function name(ForeignReader|LeafReader $reader, mixed $leaf): string
    {
        // The vendor branch comes first, but the object may be the App
        // narrowing helper at runtime. Fires.
        return $reader->text($leaf) ?? '';
    }

    public function foreignOnly(ForeignReader|ImposterReader $reader, mixed $leaf): string
    {
        // Neither branch is first-party. Silent.
        return $reader->text($leaf) ?? '';
    }
}
