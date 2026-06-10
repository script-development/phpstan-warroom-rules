<?php

declare(strict_types = 1);

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Routing\Controller;

final class UsesCurrentUserAttribute extends Controller
{
    // Canonical replacement shape — container-attribute injection resolves
    // the authenticated user. No body call required; the rule must not fire.
    public function store(#[CurrentUser] User $user): User
    {
        return $user;
    }
}
