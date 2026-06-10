<?php

declare(strict_types = 1);

namespace App\Http\Requests;

use App\DataTransferObjects\StoreUserData;
use Illuminate\Foundation\Http\FormRequest;

final class CompliantToDtoRequest extends FormRequest
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

    public function toDto(): StoreUserData
    {
        /** @var string $name */
        $name = $this->validated()['name'];

        return new StoreUserData($name);
    }
}
