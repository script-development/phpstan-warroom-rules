<?php

declare(strict_types = 1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Request;

final class RequestUserInFormRequest extends FormRequest
{
    // FormRequest descendants are intentionally out of scope — container-
    // attribute injection does not apply to FormRequest::rules() / toDto() /
    // authorize() invocations. Both call shapes below must be silent.
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * @return array<string, mixed>
     */
    public function payload(Request $request): array
    {
        return ['user' => $request->user()];
    }
}
