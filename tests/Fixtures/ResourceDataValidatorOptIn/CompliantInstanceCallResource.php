<?php

declare(strict_types = 1);

namespace App\Http\Resources;

use App\Models\Project;

final class CompliantInstanceCallResource extends ResourceData
{
    public const array EAGER_LOAD_COUNT = ['issues'];

    public function hydrate(Project $project): void
    {
        // Instance form is accepted for liberal compatibility with the
        // source-of-truth kendo arch test's permissive matcher.
        $this->validateRelationsLoaded($project);
    }
}
