<?php

declare(strict_types = 1);

namespace App\Http\Controllers;

use App\Actions\User\CreateUserAction;
use App\DataTransferObjects\Input\User\CreateUserInput;
use App\Models\User;

final readonly class CompliantActionDelegation
{
    public function __construct(
        private CreateUserAction $action,
    ) {}

    public function store(string $name, string $email): User
    {
        // Canonical Controller → DTO → Action → Result pipeline. No direct
        // Eloquent persistence call — the Action owns the mutation, the
        // transaction boundary, and the audit-row write.
        $input = new CreateUserInput($name, $email);
        $result = $this->action->execute($input);

        return $result->user;
    }
}
