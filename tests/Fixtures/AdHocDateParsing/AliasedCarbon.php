<?php

declare(strict_types = 1);

namespace App\Actions\Reporting;

use Carbon\CarbonImmutable as C;

final class AliasedCarbon
{
    public function execute(string $raw): C
    {
        return C::parse($raw);
    }
}
