<?php

declare(strict_types = 1);

namespace App\Http\Resources;

use App\Models\Project;

final class EmptyConstResource extends ResourceData
{
    public const array EAGER_LOAD_COUNT = [];

    public const array EAGER_LOAD_SUM = [];

    public static function fromModel(Project $project): self
    {
        return new self([
            'id' => $project->id,
            'name' => $project->name,
        ]);
    }
}
