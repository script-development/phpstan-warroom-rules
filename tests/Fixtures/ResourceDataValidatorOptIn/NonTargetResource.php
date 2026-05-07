<?php

declare(strict_types = 1);

namespace App\Http\Resources;

use App\Models\Project;

final class NonTargetResource extends ResourceData
{
    // Declares neither EAGER_LOAD_COUNT nor EAGER_LOAD_SUM — out of scope.

    public static function fromModel(Project $project): self
    {
        return new self([
            'id' => $project->id,
            'name' => $project->name,
        ]);
    }
}
