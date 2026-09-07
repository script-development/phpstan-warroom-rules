<?php

declare(strict_types = 1);

namespace App\Http\Requests\Reporting;

use Carbon\Carbon;

final class CreateFromFormatInRequest
{
    /**
     * @return array<string, Carbon|false>
     */
    public function toDto(string $raw): array
    {
        return ['from' => Carbon::createFromFormat('Y-m-d', $raw)];
    }
}
