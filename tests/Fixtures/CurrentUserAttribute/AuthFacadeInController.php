<?php

declare(strict_types = 1);

namespace App\Http\Controllers;

use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Auth;

final class AuthFacadeInController extends Controller
{
    public function store(): ?object
    {
        return Auth::user();
    }
}
