<?php

declare(strict_types = 1);

namespace App\Http\Controllers;

use App\Http\Resources\EmailResource;

final class CompliantReturnsResourceDirectly
{
    public function update(object $email): EmailResource
    {
        // The canonical compliant shape — return the resource directly (200).
        return EmailResource::fromModel($email);
    }
}
