<?php

declare(strict_types = 1);

// Stub classes referenced by ForbidEloquentMutationInControllers fixtures. The
// rule scopes detection on PHPStan reflection (namespace gate + receiver-type
// `ObjectType::isSuperTypeOf()` against `Illuminate\Database\Eloquent\Model`
// and `Illuminate\Database\Eloquent\Builder`). The real Eloquent Model + Builder
// live in `vendor/illuminate/database/Eloquent/` and PHPStan can resolve them;
// these stubs provide territory-shaped App\Models\* + a non-Model service
// receiver + a request stub for fixture authoring.

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Territory-shaped User model — extends real Eloquent\Model so PHPStan's type
 * resolution against `ObjectType(Model::class)` matches cleanly. The public
 * properties + minimal method overrides keep fixtures readable without
 * pulling in mass-assignment / fillable scaffolding the rule doesn't inspect.
 */
final class User extends Model
{
    public int $id = 1;

    public string $name = '';

    public string $email = '';
}

/**
 * Second territory model so multi-violation fixtures can exercise distinct
 * receiver classes (e.g., `$user->save()` + `$post->delete()` in the same
 * controller method, two separate violations at distinct lines on distinct
 * receivers).
 */
final class Post extends Model
{
    public int $id = 1;

    public string $title = '';
}

namespace App\Services;

/**
 * Non-Eloquent service receiver — exercises the rule's receiver-type gate.
 * A controller calling `$service->save()` on this class MUST NOT fire because
 * `MyService` is not a subtype of `Illuminate\Database\Eloquent\Model`.
 */
final class MyService
{
    public function save(): bool
    {
        return true;
    }

    public function delete(): bool
    {
        return true;
    }
}

namespace App\DataTransferObjects\Input\User;

/**
 * Action-input DTO. Used by the action-delegation compliant fixture to exercise
 * the legitimate Controller → DTO → Action → Model pipeline.
 */
final readonly class CreateUserInput
{
    public function __construct(
        public string $name,
        public string $email,
    ) {}
}

namespace App\DataTransferObjects\Result\User;

use App\Models\User;

/**
 * Action-return shape. Used by the action-delegation compliant fixture.
 */
final readonly class CreateUserResult
{
    public function __construct(
        public User $user,
    ) {}
}

namespace App\Actions\User;

use App\DataTransferObjects\Input\User\CreateUserInput;
use App\DataTransferObjects\Result\User\CreateUserResult;
use App\Models\User;

/**
 * Stub Action — invoked from the action-delegation compliant fixture to show
 * the canonical shape (`$this->action->execute($dto)`).
 */
final readonly class CreateUserAction
{
    public function execute(CreateUserInput $input): CreateUserResult
    {
        return new CreateUserResult(new User);
    }
}
