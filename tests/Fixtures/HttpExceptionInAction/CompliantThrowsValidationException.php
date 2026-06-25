<?php

declare(strict_types = 1);

namespace App\Actions\Location;

use Illuminate\Validation\ValidationException;

final readonly class CompliantThrowsValidationException
{
    public function execute(object $validator, bool $invalid): void
    {
        // ValidationException is EXPLICITLY out of scope — Actions legitimately
        // throw it for stateful / cross-field validation that cannot live in a
        // static FormRequest. It is not a Symfony HttpException, so the rule
        // never fires here.
        if ($invalid) {
            throw new ValidationException($validator);
        }
    }
}
