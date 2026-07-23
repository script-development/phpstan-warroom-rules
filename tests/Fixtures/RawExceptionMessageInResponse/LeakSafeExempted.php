<?php

declare(strict_types = 1);

namespace App\Mcp\Tools;

use Laravel\Mcp\Response;
use RuntimeException;

final class LeakSafeExempted
{
    public function handle(RuntimeException $e): Response
    {
        // @leak-safe: this exception type carries only an app-authored,
        // payload-free message (the SendCodyReportAction shape).
        return Response::error('Report failed: ' . $e->getMessage());
    }
}
