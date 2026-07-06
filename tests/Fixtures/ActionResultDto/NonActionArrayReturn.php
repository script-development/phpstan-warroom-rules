<?php

declare(strict_types = 1);

namespace App\Services;

final class NonActionArrayReturn
{
    // An `execute()` returning `array` OUTSIDE `App\Actions` — the namespace
    // gate keeps the rule silent. Only Actions are in scope.
    public function execute(): array
    {
        return ['not' => 'an action'];
    }
}
