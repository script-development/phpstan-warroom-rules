<?php

declare(strict_types = 1);

namespace App\Actions\Foo;

use App\Models\User;

/**
 * Namespace gate excludes — `App\Actions\*` is OUT of scope. Actions are
 * exactly where the mutation belongs; the rule's scope is the controller
 * surface only.
 */
final readonly class CreateBarAction
{
    public function execute(User $user): User
    {
        $user->name = 'Bar';
        $user->save();
        $user->update(['email' => 'bar@example.test']);
        User::create(['name' => 'Baz', 'email' => 'baz@example.test']);
        $user->delete();

        return $user;
    }
}
