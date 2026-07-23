<?php

declare(strict_types = 1);

namespace App\Mcp\Tools;

use Laravel\Mcp\Response;
use RuntimeException;

final class LeakSafeSameLineExempted
{
    public function handle(RuntimeException $e): Response
    {
        return Response::error('Report failed: ' . $e->getMessage()); // @leak-safe: app-authored, payload-free
    }
}
