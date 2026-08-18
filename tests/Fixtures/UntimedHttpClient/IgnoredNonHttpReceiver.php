<?php

declare(strict_types = 1);

namespace App\Services\Untimed;

/**
 * A `->get()` on an unrelated object whose chain root is not the Http facade.
 * The rule must not fire — `get` as a method name is not exclusive to HTTP.
 */
final class LocalRepository
{
    public function get(string $id): string
    {
        return $id;
    }
}

final class IgnoredNonHttpReceiver
{
    public function fetch(LocalRepository $repo): string
    {
        return $repo->get('x');
    }
}
