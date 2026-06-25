<?php

declare(strict_types = 1);

namespace App\Actions\Location;

use Symfony\Component\HttpKernel\Exception\HttpException;

final readonly class ThrowsHttpException
{
    public function execute(bool $exists): void
    {
        if ($exists) {
            throw new HttpException(422, 'Override already exists.');
        }
    }
}
