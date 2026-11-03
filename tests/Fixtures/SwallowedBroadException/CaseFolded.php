<?php

declare(strict_types = 1);

namespace App\Services\Swallowing;

use THROWABLE;

final class CaseFolded
{
    public function spelledInAnotherCase(Collaborator $service): void
    {
        try {
            $service->fetch();
        } catch (THROWABLE $e) {
        }
    }
}
