<?php

declare(strict_types = 1);

namespace App\Http\Controllers;

use Throwable;

final readonly class FacadeSinkUnrelatedReceiver
{
    public function __construct(
        private FacadeSinkUnrelatedReceiverFormatter $formatter,
    ) {}

    public function handle(Throwable $e): string
    {
        return $this->formatter->make('failed: ' . $e->getMessage());
    }
}

final class FacadeSinkUnrelatedReceiverFormatter
{
    public function make(string $content): string
    {
        return $content;
    }
}
