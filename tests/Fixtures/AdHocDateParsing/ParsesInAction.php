<?php

declare(strict_types = 1);

namespace App\Actions\Reporting;

use Carbon\CarbonImmutable;

final class ParsesInAction
{
    public function execute(string $from): CarbonImmutable
    {
        return CarbonImmutable::parse($from);
    }
}
