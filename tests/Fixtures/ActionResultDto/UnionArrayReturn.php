<?php

declare(strict_types = 1);

namespace App\Actions;

use App\DataTransferObjects\SomeResultData;

final readonly class UnionArrayReturn
{
    // `array|SomeResultData` — a union member of `array` is still an escape
    // hatch: the caller can receive the untyped struct. Fires.
    public function execute(bool $flag): array|SomeResultData
    {
        return $flag ? new SomeResultData('s', 'q') : ['secret' => 's'];
    }
}
