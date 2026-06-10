<?php

declare(strict_types = 1);

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

use function assert;

final class RequestUserInControllerWithAssert extends Controller
{
    public function store(Request $request): User
    {
        // Exact PR #263 shape — the assert is downstream noise; only the
        // $request->user() call fires.
        $user = $request->user();
        assert($user instanceof User);

        return $user;
    }
}
