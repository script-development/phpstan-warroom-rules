<?php

declare(strict_types = 1);

namespace App\Http\Controllers\CaseFolding;

use App\Http\Resources\EmailResource;
use App\Http\Resources\ResourceData;
use App\Models\Project;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Laravel\Mcp\Response;
use RuntimeException;

/**
 * WR-1291. PHP dispatches method names case-insensitively, so each call below
 * is the call its lower-case spelling makes, and every rule must read it that
 * way.
 */
final class UpperCaseController extends Controller
{
    public function inlineArray(): JsonResponse
    {
        return response()->JSON(['request_id' => 'r'], 202);
    }

    public function wrappedResource(object $email): JsonResponse
    {
        return response()->Json(EmailResource::fromModel($email), 201);
    }

    public function savesModel(User $user): User
    {
        $user->SAVE();

        return $user;
    }

    public function createsModel(): void
    {
        User::Create(['name' => 'n']);
    }

    public function requestUser(Request $request): ?object
    {
        return $request->USER();
    }
}

final class UpperCaseMcpTool
{
    public function leaks(RuntimeException $e): Response
    {
        return Response::ERROR($e->GetMessage());
    }

    public function logs(RuntimeException $e): void
    {
        Log::ERROR($e->getMessage());
    }
}

final class UpperCaseValidatedResource extends ResourceData
{
    public const array EAGER_LOAD_SUM = ['timeEntries:minutes_spent'];

    public static function fromModel(Project $project): self
    {
        self::VALIDATERELATIONSLOADED($project);

        return new self(['id' => $project->id]);
    }
}

final class UpperCaseAuthFacadeController extends Controller
{
    public function store(): ?object
    {
        return Auth::USER();
    }
}
