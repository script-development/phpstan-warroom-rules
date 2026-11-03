<?php

declare(strict_types = 1);

final class IgnoreWithEmptyReason
{
    public function handle(): void
    {
        undefinedFixtureHelper(); // @phpstan-ignore function.notFound ()
    }
}
