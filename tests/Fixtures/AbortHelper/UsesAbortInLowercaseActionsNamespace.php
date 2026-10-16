<?php

declare(strict_types = 1);

namespace app\actions\Location;

final readonly class UsesAbortInLowercaseActionsNamespace
{
    public function execute(bool $exists): void
    {
        abort_unless($exists, 404);
    }
}
