<?php

declare(strict_types = 1);

final class UsesAbortIf
{
    public function handle(bool $condition): void
    {
        abort_if($condition, 403);
    }
}
