<?php

declare(strict_types = 1);

namespace App\Http\Controllers;

use Illuminate\Contracts\Routing\ResponseFactory;
use Throwable;

final readonly class FacadeSinkInjectedGetMessage
{
    public function __construct(
        private ResponseFactory $responses,
    ) {}

    public function handle(Throwable $e): mixed
    {
        return $this->responses->make('failed: ' . $e->getMessage());
    }
}
