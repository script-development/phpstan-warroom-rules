<?php

declare(strict_types = 1);

namespace App\Mcp\Tools;

use Laravel\Mcp\Response;
use Throwable;

final class AppAuthoredLiteralMessage
{
    public function handle(Throwable $e): Response
    {
        // A stable, app-authored message with no raw exception detail — the
        // correct client-facing response. The exception is available ($e) but
        // its message never reaches the sink. Does not fire.
        return Response::error('Something went wrong. Please try again.');
    }
}
