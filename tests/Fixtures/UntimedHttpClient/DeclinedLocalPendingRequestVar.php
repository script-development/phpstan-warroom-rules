<?php

declare(strict_types = 1);

namespace App\Services\Untimed;

use Illuminate\Http\Client\Factory as HttpFactory;

/**
 * The timeout is applied when building `$request`, then the send happens on a
 * later statement. The send-site expression sees a `PendingRequest`-typed root
 * (not the `Factory` entry), so the rule DECLINES — the upstream timeout is
 * out of view and firing would be a false positive.
 */
final class DeclinedLocalPendingRequestVar
{
    public function __construct(
        private HttpFactory $http,
    ) {}

    public function fetch(string $url, string $token): void
    {
        $request = $this->http->withToken($token)->timeout(30);

        $request->get($url);
    }
}
