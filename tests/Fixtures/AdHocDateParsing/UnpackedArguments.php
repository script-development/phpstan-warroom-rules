<?php

declare(strict_types = 1);

namespace App\Actions\Reporting;

use Carbon\Carbon;
use Carbon\CarbonImmutable;
use DateTimeImmutable;
use Generator;

/**
 * Deliberately in a NON-boundary namespace. Every call here spreads an array
 * into the call rather than writing the arguments out, which since PHP 5.6 is
 * an ordinary way to forward a decoded slot — and it means the AST carries ONE
 * `Arg` whose value is the ARRAY, not one `Arg` per value the call receives.
 */
final class UnpackedArguments
{
    /**
     * @param list<string> $raw
     */
    public function unpackedIntoTheDecodedSlot(array $raw): void
    {
        CarbonImmutable::parse(...$raw);
    }

    /**
     * The decoded slot is the SECOND parameter here, so the unpacked array
     * carries the format at 0 and the string to decode at 1.
     *
     * @param array{string, string} $pair
     */
    public function unpackedFormatAndTime(array $pair): void
    {
        Carbon::createFromFormat(...$pair);
    }

    /**
     * @param list<string> $args
     */
    public function unpackedIntoTheConstructor(array $args): void
    {
        new DateTimeImmutable(...$args);
    }

    /**
     * @param list<string> $a
     */
    public function unpackedIntoAFunction(array $a): void
    {
        strtotime(...$a);
    }

    /**
     * A string-keyed unpack is a named-argument spread: `time` names the slot.
     *
     * @param array{time: string} $named
     */
    public function unpackedByName(array $named): void
    {
        CarbonImmutable::parse(...$named);
    }

    /**
     * FIRES, and this is the DECISION the unpack branch has to make: an untyped
     * bag carries no element information, so the analyser cannot prove the
     * value reaching the slot is not a string. Unknown means it may be one —
     * the same direction the gate already takes on a bare `mixed` scalar, and
     * exactly the shape an unpacked `$request->all()` has.
     *
     * @param array<mixed> $bag
     */
    public function unpackedUntypedBag(array $bag): void
    {
        CarbonImmutable::parse(...$bag);
    }

    /**
     * Two spreads. The first is a one-element constant array holding the
     * format, so it does NOT reach the decoded slot — skipping it needs its
     * LENGTH, and the string to decode is at offset 0 of the second.
     *
     * PHP forbids a positional argument after an unpack, so a second spread is
     * the only way to write this.
     */
    public function unpackedFormatThenUnpackedTime(string $raw): void
    {
        Carbon::createFromFormat(...['Y-m-d'], ...[$raw]);
    }

    /**
     * A plain argument, then two spreads, into the verb whose decoded slot is
     * the THIRD parameter. The running index has to be ADVANCED by each
     * constant spread's length rather than SET to it: the format takes slot 0,
     * the locale spread takes slot 1, and the string to decode is at offset 0
     * of the third argument only if the count carried over from the first two.
     */
    public function unpackedAfterAPlainArgument(string $raw): void
    {
        Carbon::createFromLocaleFormat('Y-m-d', ...['nl'], ...[$raw]);
    }

    /**
     * SILENT, and a DELIBERATE MISS: a Traversable spread has no offsets and no
     * known length, so nothing can be said about which value lands in the
     * decoded slot. The rule declines rather than guessing, the same call it
     * makes on a dynamic class expression.
     *
     * @param Generator<int, string> $values
     */
    public function unpackedTraversable(Generator $values): void
    {
        CarbonImmutable::create(...$values);
    }

    /**
     * SILENT: integer components assemble a date, spread or not.
     */
    public function unpackedIntegerComponents(): void
    {
        CarbonImmutable::create(...[2_026, 9, 8]);
    }

    /**
     * SILENT: a timestamp is already an instant, and `createFromTimestamp` is
     * not in the method table at all.
     *
     * @param list<int> $ts
     */
    public function unpackedTimestamp(array $ts): void
    {
        CarbonImmutable::createFromTimestamp(...$ts);
    }

    /**
     * SILENT: the unpacked array is proven to hold integers, so nothing that
     * reaches the decoded slot could be a string.
     *
     * @param list<int> $components
     */
    public function unpackedIntegerList(array $components): void
    {
        CarbonImmutable::create(...$components);
    }
}
