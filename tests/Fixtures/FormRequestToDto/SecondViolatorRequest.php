<?php

declare(strict_types = 1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

// A second concrete violator, analysed alongside ViolatorRequest to prove
// that exempting one FQCN does NOT globally silence the rule — the other
// non-exempt violator must still fire.
final class SecondViolatorRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string'],
        ];
    }
}
