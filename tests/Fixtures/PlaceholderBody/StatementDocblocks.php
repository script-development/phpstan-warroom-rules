<?php

declare(strict_types = 1);

namespace App\Services\Placeholders;

final class StatementDocblocks
{
    public function docblockOnAStatementIsDocumentation(): int
    {
        /** ... existing code ... */
        $count = 1;

        /**
         * ... rest of the code ...
         */
        $count++;

        return $count;
    }

    public function lineAndBlockElisionsStillFire(): int
    {
        $count = 1;
        // ... existing code ...
        $count++;
        /*
         * ... rest of the method ...
         * kept
         */
        $count++;
        /** @var int $count */
        $count += 0;

        return $count;
    }
}
