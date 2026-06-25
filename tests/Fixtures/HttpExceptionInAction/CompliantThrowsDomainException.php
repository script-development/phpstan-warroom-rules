<?php

declare(strict_types = 1);

namespace App\Actions\Location;

use App\Exceptions\OverrideAlreadyExistsException;

final readonly class CompliantThrowsDomainException
{
    public function execute(bool $exists): void
    {
        // The canonical alternative: a custom domain exception the renderer
        // maps to a status. Not an HTTP-layer exception — the rule is silent.
        if ($exists) {
            throw new OverrideAlreadyExistsException('Override already exists.');
        }
    }
}
