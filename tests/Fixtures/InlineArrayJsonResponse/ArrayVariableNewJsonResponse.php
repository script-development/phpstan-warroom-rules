<?php

declare(strict_types = 1);

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;

final class ArrayVariableNewJsonResponse
{
    public function enable(): JsonResponse
    {
        // The same violation laundered through an array-typed variable (kendo
        // `enable()` shape). The type gate resolves `$result` to an array, so it
        // fires — the indirection does not hide the untyped struct.
        $result = ['secret' => 's', 'qr_code' => 'q'];

        return new JsonResponse($result);
    }
}
