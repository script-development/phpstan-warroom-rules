<?php

declare(strict_types = 1);

namespace App\Actions\Foo;

use Illuminate\Database\DatabaseManager;

final readonly class InjectsDatabaseManager
{
    public function __construct(
        private DatabaseManager $db,
    ) {}

    public function execute(): void
    {
        $this->db->connection()->statement('SELECT 1');
    }
}
