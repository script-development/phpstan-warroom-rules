<?php

declare(strict_types = 1);

namespace App\Actions\Widget;

final readonly class EmptyExecuteMethodSkipped
{
    public function execute(): void
    {
        // No transaction, no body — rule should short-circuit cleanly.
    }
}
