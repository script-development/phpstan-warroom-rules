<?php

declare(strict_types = 1);

namespace App\Http\Controllers;

use App\Services\MyService;

final class CompliantLocalVarNonModel
{
    public function run(): void
    {
        // Local var of a NON-Model class calling `save()` — the receiver-type
        // gate still discriminates under flow scope. Only
        // `Illuminate\Database\Eloquent\Model` / `Builder` subtypes fire.
        $service = new MyService;
        $service->save();
        $service->delete();
    }
}
