<?php

declare(strict_types = 1);

namespace App\Actions\Foo;

final readonly class SingleWrite
{
    public function execute(object $a): void
    {
        $a->save();
    }
}
