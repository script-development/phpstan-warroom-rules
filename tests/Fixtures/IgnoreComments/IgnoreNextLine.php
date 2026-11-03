<?php

declare(strict_types = 1);

final class IgnoreNextLine
{
    public function handle(): void
    {
        // @phpstan-ignore-next-line the reason a reviewer reads
        undefinedFixtureHelper();
    }
}
