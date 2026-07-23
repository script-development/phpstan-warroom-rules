<?php

declare(strict_types = 1);

namespace App\Services\Untimed;

use Illuminate\Support\Facades\Http;

final class DeclinedMacroStaticRoot
{
    public function fetch(string $url): void
    {
        // A Macroable entry — `github()` is outside the known PendingRequest
        // builder surface and may return a PRE-TIMED request. Declines (the
        // #57 review's Minor: this was misclassified as untimed).
        Http::github()->get($url);
    }
}
