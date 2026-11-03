<?php

declare(strict_types = 1);

namespace ScriptDevelopment\PhpstanWarroomRules\DiffGuards;

use function count;
use function sprintf;

/**
 * Writes a run as plain lines, or as GitHub workflow commands (`--format=github`).
 *
 * The denominator and the verdict print in both formats; only findings and the cannot-run
 * reason become `::error` annotations, placed by repository-relative file and line.
 */
final readonly class Reporter
{
    /**
     * @param resource $output
     */
    public function __construct(
        private string $format,
        private mixed $output,
    ) {}

    /**
     * @param list<Finding> $kept the findings no waiver excused
     */
    public function result(CheckResult $result, array $kept, string $prefix): void
    {
        foreach ($kept as $finding) {
            $file = $prefix . $finding->file;

            $this->write($this->format === 'github'
                ? '::error file=' . self::property($file) . ',line=' . $finding->line . ',title=' . self::property($result->check) . '::' . self::data($finding->message)
                : "  {$file}:{$finding->line}  {$finding->message}");
        }

        $unit = $result->unit . ($result->compared === 1 ? '' : 's');
        $this->write($result->compared === 0
            ? "{$result->check}: compared nothing — no {$result->unit}s in this change"
            : "{$result->check}: " . count($kept) . ' finding' . (count($kept) === 1 ? '' : 's') . " over {$result->compared} {$unit}");
    }

    public function waived(Finding $finding, Waiver $waiver): void
    {
        $this->write("  waived  {$finding->check} {$finding->subject}: {$waiver->reason}");
    }

    public function unusedWaiver(Waiver $waiver): void
    {
        $this->write("  unused waiver  {$waiver->check} {$waiver->path} matched no finding in this change");
    }

    public function verdict(string $base, string $fork, int $changed, int $failed): void
    {
        $this->write(sprintf(
            'diff-guards: %s — %d finding%s; compared HEAD with the merge base %s of %s over %d changed path%s',
            $failed === 0 ? 'PASSED' : 'FAILED',
            $failed,
            $failed === 1 ? '' : 's',
            mb_substr($fork, 0, 12),
            $base,
            $changed,
            $changed === 1 ? '' : 's',
        ));
    }

    public function cannotRun(CannotRun $exception): void
    {
        $line = "diff-guards: {$exception->kind} — {$exception->getMessage()}";

        $this->write($this->format === 'github' ? '::error title=diff-guards ' . $exception->kind . '::' . self::data($line) : $line);
    }

    private function write(string $line): void
    {
        fwrite($this->output, $line . "\n");
    }

    private static function data(string $value): string
    {
        return strtr($value, ['%' => '%25', "\r" => '%0D', "\n" => '%0A']);
    }

    private static function property(string $value): string
    {
        return strtr($value, ['%' => '%25', "\r" => '%0D', "\n" => '%0A', ':' => '%3A', ',' => '%2C']);
    }
}
