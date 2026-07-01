<?php

declare(strict_types = 1);

namespace App\Http\Requests;

use App\DataTransferObjects\StoreUserData;
use Illuminate\Foundation\Http\FormRequest;

/**
 * ADR-0020 bulk-list convention: a concrete FormRequest that converts its
 * validated input to a `list<…Data>` via `toDtos()` (plural) instead of a
 * single `toDto()`. This is the canonical bulk-reorder shape — the rule must
 * NOT flag it. The singular-only check false-positived on every request of
 * this shape; this fixture pins the plural leg of the contract.
 */
final class CompliantToDtosRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'names' => ['required', 'array'],
        ];
    }

    /**
     * @return list<StoreUserData>
     */
    public function toDtos(): array
    {
        /** @var list<string> $names */
        $names = $this->validated()['names'];

        return array_map(
            static fn(string $name): StoreUserData => new StoreUserData($name),
            $names,
        );
    }
}
