<?php

declare(strict_types = 1);

namespace App\Http\Controllers;

use Illuminate\Routing\Controller;

final class AuthHelperInController extends Controller
{
    public function store(): ?object
    {
        return auth()->user();
    }
}
