<?php

declare(strict_types = 1);

namespace App\Services\Untimed;

use Illuminate\Http\Client\PendingRequest;

/**
 * The builder is held on a property and sent on a later statement, so the
 * send-site expression cannot see whether a timeout was applied. The rule
 * DECLINES (no error) rather than risk a false positive — a documented,
 * deliberate miss. The surviving named-list Pest test still covers this class.
 */
final class DeclinedSplitChainProperty
{
    public function __construct(
        private PendingRequest $client,
    ) {}

    public function fetch(string $url): void
    {
        $this->client->get($url);
    }
}
