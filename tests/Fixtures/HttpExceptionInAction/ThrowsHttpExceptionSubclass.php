<?php

declare(strict_types = 1);

namespace App\Actions\Location;

use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final readonly class ThrowsHttpExceptionSubclass
{
    public function execute(bool $missing): void
    {
        // Subclass throw — the import-checking arch test catches the import,
        // but the type-aware rule catches it via the HttpExceptionInterface
        // subtype relationship regardless of which subclass is thrown.
        if ($missing) {
            throw new NotFoundHttpException('Not found.');
        }
    }
}
