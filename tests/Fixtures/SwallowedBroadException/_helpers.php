<?php

declare(strict_types = 1);

// Laravel's global reporting helpers, declared for analysis only: the rule
// resolves each callee through the reflection provider, so a helper PHPStan
// cannot find is not a reporting call.

function report(string|Throwable $exception): void {}

function report_if(bool $condition, string|Throwable $exception): void {}

function report_unless(bool $condition, string|Throwable $exception): void {}

function logger(?string $message = null): mixed
{
    return null;
}

function info(string $message): void {}
