<?php

declare(strict_types = 1);

use App\Models\RegularModel;

final class UpdatesRegularModel
{
    public function rename(RegularModel $model): void
    {
        $model->update(['name' => 'foo']);
    }
}
