<?php

declare(strict_types = 1);

namespace App\Http\Resources;

use App\Models\Project;

final class CompliantSelfCallResource extends ResourceData
{
    public const array EAGER_LOAD_SUM = ['timeEntries:minutes_spent'];

    public static function fromModel(Project $project): self
    {
        self::validateRelationsLoaded($project);

        return new self([
            'id' => $project->id,
            'name' => $project->name,
        ]);
    }
}
