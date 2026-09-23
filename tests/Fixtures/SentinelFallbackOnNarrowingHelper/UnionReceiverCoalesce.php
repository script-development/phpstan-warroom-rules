<?php

declare(strict_types = 1);

namespace App\Imports;

use Acme\Vendor\BareReader;
use Acme\Vendor\ForeignReader;
use Acme\Vendor\ForeignSource;
use Acme\Vendor\XmlReader;
use App\Support\CsvReader;
use App\Support\LeafReader;
use App\Support\LeafSource;

final class UnionReceiverCoalesce
{
    public function foreignFirst(ForeignReader|LeafReader $reader, mixed $leaf): string
    {
        return $reader->text($leaf) ?? '';
    }

    public function appFirst(LeafReader|XmlReader $reader, mixed $leaf): string
    {
        return $reader->text($leaf) ?? '';
    }

    public function bothFirstParty(CsvReader|LeafReader $reader, mixed $leaf): string
    {
        return $reader->text($leaf) ?? '';
    }

    public function interfaceBranch(ForeignReader|LeafSource $reader, mixed $leaf): string
    {
        return $reader->text($leaf) ?? '';
    }

    public function nullsafeForeignFirst(ForeignReader|LeafReader|null $reader, mixed $leaf): string
    {
        return $reader?->text($leaf) ?? '';
    }

    public function branchWithoutTheMethod(BareReader|LeafReader $reader, mixed $leaf): string
    {
        return $reader->text($leaf) ?? '';
    }

    public function intersectionForeignFirst(ForeignSource&LeafSource $reader, mixed $leaf): string
    {
        return $reader->text($leaf) ?? '';
    }
}
