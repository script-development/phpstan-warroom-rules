<?php

declare(strict_types = 1);

namespace App\Actions;

final readonly class IterableReturn
{
    /**
     * `iterable` admits arrays — the same hole, an adjacent spelling. Fires.
     *
     * @return iterable<string>
     */
    public function execute(): iterable
    {
        yield 'a';
        yield 'b';
    }
}
