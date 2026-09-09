<?php

declare(strict_types = 1);

namespace App\Actions\Reporting;

use DateTimeImmutable;

final class NewDateTimeWithArgument
{
    public function execute(string $raw): DateTimeImmutable
    {
        return new DateTimeImmutable($raw);
    }
}
