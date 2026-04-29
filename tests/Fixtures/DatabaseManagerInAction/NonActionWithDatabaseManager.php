<?php

declare(strict_types = 1);

namespace App\Services\Foo;

use Illuminate\Database\DatabaseManager;

final readonly class NonActionWithDatabaseManager
{
    public function __construct(
        private DatabaseManager $db,
    ) {}

    public function run(): void
    {
        $this->db->connection()->statement('SELECT 1');
    }
}
