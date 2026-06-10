<?php

declare(strict_types = 1);

use Illuminate\Support\Facades\Auth;

// Top-level call outside any namespace — `$scope->getNamespace()` returns
// null, so the rule must short-circuit at the null-namespace gate.
// Silent. Kills the FalseValue mutant on the `getNamespace() === null` guard.
Auth::user();
