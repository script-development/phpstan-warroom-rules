<?php

declare(strict_types = 1);

namespace App\Services\Swallowing\Local;

use RuntimeException;

final class Exception extends RuntimeException {}

final class LocalExceptionClass
{
    public function catchesTheNamespaceLocalClass(): void
    {
        try {
            throw new Exception('local');
        } catch (Exception $e) {
        }
    }
}
