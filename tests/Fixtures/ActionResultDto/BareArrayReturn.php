<?php

declare(strict_types = 1);

namespace App\Actions;

final readonly class BareArrayReturn
{
    /**
     * Bare `: array` — the seed shape (kendo `EnableCentralTwoFactorAction`
     * returned `array{secret, qr_code}`). Fires.
     *
     * @return array{secret: string, qr_code: string}
     */
    public function execute(): array
    {
        return ['secret' => 's', 'qr_code' => 'q'];
    }
}
