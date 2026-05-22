<?php

declare(strict_types = 1);

use Illuminate\Support\Facades\Auth;

// Top-level call outside any class — `$scope->getClassReflection()` returns
// null, so the rule must short-circuit at the no-class-reflection gate.
// Silent. Kills the FalseValue mutant on the null-reflection guard.
Auth::user();
