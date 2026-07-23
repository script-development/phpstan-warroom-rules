<?php

declare(strict_types = 1);

namespace App\Mcp\Tools;

use App\Exceptions\DependentModelRelationException;
use Laravel\Mcp\Response;

final class SafeMessageExceptionPassthrough
{
    public function handle(DependentModelRelationException $e): Response
    {
        // The codebook DeleteChapterTool shape: an exception whose message
        // discipline is arch-test-pinned as app-authored. Fires under the
        // default config; silent when the class is listed in
        // `safeMessageExceptionClasses`.
        return Response::error('Cannot delete: ' . $e->getMessage());
    }
}
