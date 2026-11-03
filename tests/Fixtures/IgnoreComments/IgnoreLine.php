<?php

declare(strict_types = 1);

final class IgnoreLine
{
    public function handle(): void
    {
        undefinedFixtureHelper(); // @phpstan-ignore-line the reason a reviewer reads
    }
}
