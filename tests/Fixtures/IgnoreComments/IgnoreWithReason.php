<?php

declare(strict_types = 1);

final class IgnoreWithReason
{
    public function sameLine(): void
    {
        undefinedFixtureHelper(); // @phpstan-ignore function.notFound (the reason a reviewer reads)
    }

    public function lineAbove(): void
    {
        // @phpstan-ignore function.notFound (the reason a reviewer reads)
        undefinedFixtureHelper();
    }
}
