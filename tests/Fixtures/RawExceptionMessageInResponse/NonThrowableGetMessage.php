<?php

declare(strict_types = 1);

namespace App\Mcp\Tools;

use App\Support\ValidatorBag;
use Laravel\Mcp\Response;

final class NonThrowableGetMessage
{
    public function handle(ValidatorBag $bag): Response
    {
        // getMessage() on a NON-Throwable receiver — an app-authored summary,
        // not an exception leak. The type gate keeps this silent. Does not fire.
        return Response::error('Validation: ' . $bag->getMessage());
    }
}
