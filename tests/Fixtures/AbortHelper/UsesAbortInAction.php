<?php

declare(strict_types = 1);

namespace App\Actions\Location;

final readonly class UsesAbortInAction
{
    public function execute(bool $exists): void
    {
        abort_unless($exists, 404);
    }
}
