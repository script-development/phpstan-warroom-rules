<?php

declare(strict_types = 1);

namespace App\Actions;

final readonly class VoidReturn
{
    // A command Action that returns nothing. Silent.
    public function execute(): void
    {
        // side effects only
    }
}
