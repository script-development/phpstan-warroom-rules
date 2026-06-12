<?php

declare(strict_types = 1);

namespace App\Unrelated;

// A class with the short name `FormRequest` in an unrelated namespace MUST
// NOT be matched as the rule's base class. The detection uses the FQCN, not
// the short name.
abstract class FormRequest {}

final class UnrelatedShortNameCollision extends FormRequest
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
