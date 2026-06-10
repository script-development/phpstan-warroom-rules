<?php

declare(strict_types = 1);

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;

// Compliant base-less `final` controller (no `extends Controller`) — the
// canonical replacement shape. Container-attribute injection resolves the
// authenticated user, so the rule must NOT fire even though the namespace
// gate now matches this class.
final class CurrentUserAttributeInBaselessController
{
    public function store(#[CurrentUser] User $user): User
    {
        return $user;
    }
}
