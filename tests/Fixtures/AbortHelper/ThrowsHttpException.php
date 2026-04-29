<?php

declare(strict_types = 1);

use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final class ThrowsHttpException
{
    public function handle(): never
    {
        throw new NotFoundHttpException('Resource not found.');
    }
}
