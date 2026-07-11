<?php

declare(strict_types = 1);

namespace App\Actions;

use App\DataTransferObjects\SomeResultData;

final readonly class ArrayHelperNonExecute
{
    // `execute()` returns a typed Result DTO — compliant.
    public function execute(): SomeResultData
    {
        $parts = $this->buildParts();

        return new SomeResultData($parts['secret'], $parts['qr_code']);
    }

    /**
     * A private helper returning `array` — NOT the `execute()` method, so the
     * method gate keeps it silent. Actions may shuttle arrays internally; the
     * rule polices only the public `execute()` boundary.
     *
     * @return array{secret: string, qr_code: string}
     */
    private function buildParts(): array
    {
        return ['secret' => 's', 'qr_code' => 'q'];
    }
}
