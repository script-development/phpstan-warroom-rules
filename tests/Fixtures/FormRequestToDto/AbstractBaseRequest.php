<?php

declare(strict_types = 1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

// Per-territory abstract intermediate (the entreezuil `BaseFormRequest`
// shape) — not a mutation request, declares no toDto(), and must NOT fire:
// abstract classes are exempt from the contract.
abstract class AbstractBaseRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }
}
