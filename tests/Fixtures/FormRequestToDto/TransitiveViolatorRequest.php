<?php

declare(strict_types = 1);

namespace App\Http\Requests;

// Concrete leaf extending the abstract intermediate `AbstractBaseRequest`,
// which declares no toDto() anywhere in the chain. Transitive-violation
// detection must fire HERE, at the leaf — the inverse of
// CompliantInheritedToDtoRequest (where the abstract parent supplies toDto()).
// Proves the FQCN ancestor traversal detects omission through an intermediate
// abstract layer, not only direct framework-base extension.
final class TransitiveViolatorRequest extends AbstractBaseRequest
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
