<?php

declare(strict_types = 1);

namespace App\Services\Untimed;

use Illuminate\Http\Client\Factory as HttpFactory;

final class CompliantFactoryChainTimeout
{
    public function __construct(
        private HttpFactory $http,
    ) {}

    public function fetch(string $url, string $token): void
    {
        $this->http->withToken($token)->timeout(30)->get($url);
    }
}
