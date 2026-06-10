<?php

declare(strict_types = 1);

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

final class RequestUserInController extends Controller
{
    public function store(Request $request): ?object
    {
        return $request->user();
    }
}
