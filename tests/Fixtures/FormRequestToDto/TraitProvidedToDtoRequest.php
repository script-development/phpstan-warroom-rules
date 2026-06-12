<?php

declare(strict_types = 1);

namespace App\Http\Requests;

use App\DataTransferObjects\StoreUserData;
use Illuminate\Foundation\Http\FormRequest;

// Trait-provided toDto() must satisfy the contract — the rule routes through
// PHPStan's hasNativeMethod(), which flattens trait-composed methods, mirroring
// the source-of-truth Pest test's method_exists() matcher. Pins the trait leg
// of the promise documented in the rule docblock, README, and CHANGELOG, which
// otherwise rests on an untested assumption about PHPStan internals.
trait ProvidesToDto
{
    public function toDto(): StoreUserData
    {
        /** @var string $name */
        $name = $this->validated()['name'];

        return new StoreUserData($name);
    }
}

final class TraitProvidedToDtoRequest extends FormRequest
{
    use ProvidesToDto;

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
