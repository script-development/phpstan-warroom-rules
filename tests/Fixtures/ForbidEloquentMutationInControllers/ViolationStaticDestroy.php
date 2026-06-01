<?php

declare(strict_types = 1);

namespace App\Http\Controllers;

use App\Models\User;

final class ViolationStaticDestroy
{
    public function bulkDestroy(int $id): int
    {
        return User::destroy($id);
    }
}
