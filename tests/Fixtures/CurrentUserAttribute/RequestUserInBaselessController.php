<?php

declare(strict_types = 1);

namespace App\Http\Controllers;

use Illuminate\Http\Request;

// Regression proof for the namespace-gate fix: a base-less `final` controller
// with NO `extends Controller`. This is the exact real-world shape kendo /
// ublgenie / entreezuil ship — the prior `isSubclassOf(Controller)` ancestry
// gate matched zero such classes, so the rule was a silent no-op. The
// namespace gate (`App\Http\Controllers` prefix) must flag this.
final class RequestUserInBaselessController
{
    public function store(Request $request): ?object
    {
        return $request->user();
    }
}
