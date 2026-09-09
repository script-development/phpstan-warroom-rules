<?php

declare(strict_types = 1);

namespace App\Actions\Reporting;

use Carbon\CarbonImmutable;

/**
 * The DECLINE paths, all in a non-boundary namespace so nothing here is held
 * back by the namespace gate.
 *
 * A dynamic class or method expression has no resolvable name; guessing one
 * would be a false-positive source a boundary rule cannot afford, so these are
 * accepted false negatives rather than oversights. The unrelated calls are the
 * ordinary case the rule must not touch at all.
 */
final class DynamicAndUnrelatedCalls
{
    public function dynamicClassExpression(string $raw): void
    {
        $class = CarbonImmutable::class;

        $class::parse($raw);
        new $class($raw);
    }

    public function dynamicMethodName(string $raw): void
    {
        $method = 'parse';

        CarbonImmutable::{$method}($raw);
    }

    public function dynamicFunctionName(string $raw): void
    {
        $function = 'strtotime';

        $function($raw);
    }

    public function unrelatedCalls(string $raw): string
    {
        NotADateAtAll::parse($raw);

        new NotADateAtAll($raw);

        return mb_trim($raw);
    }
}

final class NotADateAtAll
{
    public function __construct(
        public string $raw,
    ) {}

    public static function parse(string $raw): string
    {
        return $raw;
    }
}
