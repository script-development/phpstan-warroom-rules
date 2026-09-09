<?php

declare(strict_types = 1);

namespace App\Actions\Reporting;

use Carbon\CarbonImmutable;

use function strtotime;

/**
 * Named arguments move the decoded slot off its position, in both directions.
 * A source-order read of argument zero reports the calls that decode nothing
 * and stays silent on the calls that do.
 */
final class NamedArguments
{
    public function namesOnlyTheZone(): void
    {
        CarbonImmutable::create(timezone: 'Europe/Amsterdam');

        new CarbonImmutable(timezone: 'Europe/Amsterdam');
    }

    public function namesTheDecodedSlotOutOfOrder(string $raw): void
    {
        CarbonImmutable::create(month: 1, year: $raw);
        CarbonImmutable::parse(timezone: 'UTC', time: $raw);

        strtotime(baseTimestamp: 0, datetime: $raw);
    }

    public function namesADecodedSlotThatIsNotAString(): void
    {
        CarbonImmutable::parse(timezone: 'UTC', time: 1_735_689_600);
    }
}
