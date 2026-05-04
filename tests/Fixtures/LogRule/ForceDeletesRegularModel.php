<?php

declare(strict_types = 1);

use App\Models\RegularModel;

final class ForceDeletesRegularModel
{
    public function purge(RegularModel $model): void
    {
        $model->forceDelete();
    }
}
