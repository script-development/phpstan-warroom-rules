<?php

declare(strict_types = 1);

namespace App\Actions\Reporting;

use Illuminate\Support\Facades\Date;

final class DateFacade
{
    public function execute(string $raw): mixed
    {
        return Date::parse($raw);
    }
}
