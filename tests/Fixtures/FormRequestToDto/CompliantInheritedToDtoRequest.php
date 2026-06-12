<?php

declare(strict_types = 1);

namespace App\Http\Requests;

use App\DataTransferObjects\StoreUserData;
use Illuminate\Foundation\Http\FormRequest;

// The intermediate abstract layer declares toDto(); the concrete leaf
// inherits it. Both must stay clean: abstract classes are exempt, and
// inherited declarations satisfy the contract (mirroring the source-of-truth
// Pest test's method_exists() matcher).
abstract class RequestWithSharedDto extends FormRequest
{
    public function toDto(): StoreUserData
    {
        /** @var string $name */
        $name = $this->validated()['name'];

        return new StoreUserData($name);
    }
}

final class CompliantInheritedToDtoRequest extends RequestWithSharedDto
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
