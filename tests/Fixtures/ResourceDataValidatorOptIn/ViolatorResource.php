<?php

declare(strict_types = 1);

namespace App\Http\Resources;

use App\Models\Project;

final class ViolatorResource extends ResourceData
{
    public const array EAGER_LOAD_COUNT = ['issues'];

    public static function fromModel(Project $project): self
    {
        return new self([
            'id' => $project->id,
            'name' => $project->name,
        ]);
    }
}
