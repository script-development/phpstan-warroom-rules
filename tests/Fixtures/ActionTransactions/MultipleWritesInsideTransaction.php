<?php

declare(strict_types = 1);

namespace App\Actions\Foo;

use Illuminate\Database\ConnectionInterface;

final readonly class MultipleWritesInsideTransaction
{
    public function __construct(
        private ConnectionInterface $connection,
    ) {}

    public function execute(object $a, object $b): void
    {
        $this->connection->transaction(function() use ($a, $b): void {
            $a->save();
            $b->save();
        });
    }
}
