<?php

declare(strict_types = 1);

namespace App\Services\Placeholders;

use Closure;

final class NestedClosure
{
    public function outerIsWritten(): Closure
    {
        $count = 1;

        return function() use ($count): int {
            // ... existing code ...

            return $count;
        };
    }

    public function anonymousClassMethod(): object
    {
        return new class {
            public function run(): void
            {
                // ... rest of the code ...
            }
        };
    }
}
