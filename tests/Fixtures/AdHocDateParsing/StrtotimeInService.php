<?php

declare(strict_types = 1);

namespace App\Services\Reporting;

final class StrtotimeInService
{
    public function toTimestamp(string $raw): false|int
    {
        return strtotime($raw);
    }
}
