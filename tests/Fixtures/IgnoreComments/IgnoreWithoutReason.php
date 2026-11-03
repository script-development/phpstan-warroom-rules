<?php

declare(strict_types = 1);

final class IgnoreWithoutReason
{
    public function handle(): void
    {
        undefinedFixtureHelper(); // @phpstan-ignore function.notFound
    }
}
