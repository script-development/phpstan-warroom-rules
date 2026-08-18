<?php

declare(strict_types = 1);

namespace App\Services\Untimed;

use Illuminate\Support\Facades\Http;

final class DeclinedWithOptionsComputed
{
    public function fetch(string $url): void
    {
        // The options are computed — a helper return whose keys the rule
        // cannot see. Absence of 'timeout' is unprovable, so the chain
        // DECLINES (the #57 review's Major: flagging this was a false
        // positive on a possibly-timed request).
        Http::withOptions($this->options())->get($url);
    }

    /**
     * @return array<string, mixed>
     */
    private function options(): array
    {
        return ['timeout' => 5, 'verify' => false];
    }
}
