<?php

declare(strict_types = 1);

namespace App\Actions\Foo;

use Illuminate\Database\ConnectionInterface;

final readonly class InjectsConnectionInterface
{
    public function __construct(
        private ConnectionInterface $connection,
    ) {}

    public function execute(): void
    {
        $this->connection->statement('SELECT 1');
    }
}
