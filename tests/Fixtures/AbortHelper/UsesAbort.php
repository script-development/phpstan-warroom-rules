<?php

declare(strict_types = 1);

final class UsesAbort
{
    public function handle(): never
    {
        abort(404);
    }
}
