<?php

declare(strict_types = 1);

use App\Models\RegularModel;

final class DestroysRegularModel
{
    public function purge(): void
    {
        RegularModel::destroy([1]);
    }
}
