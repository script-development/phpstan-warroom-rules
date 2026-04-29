<?php

declare(strict_types = 1);

namespace App\Actions\Foo;

use Illuminate\Database\ConnectionInterface;

final readonly class MultipleWritesNoTransaction
{
    public function __construct(
        private ConnectionInterface $connection,
    ) {}

    public function execute(object $a, object $b): void
    {
        $a->save();
        $b->save();
    }
}
