<?php

declare(strict_types = 1);

namespace App\Actions;

use App\DataTransferObjects\SomeResultData;

final readonly class ResultDtoReturn
{
    // The compliant shape — a typed Result DTO. Silent.
    public function execute(): SomeResultData
    {
        return new SomeResultData('s', 'q');
    }
}
