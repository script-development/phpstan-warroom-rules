<?php

declare(strict_types = 1);

namespace App\ActionsLegacy;

final class UsesAbortInActionsLegacy
{
    public function handle(): never
    {
        abort(404);
    }
}
