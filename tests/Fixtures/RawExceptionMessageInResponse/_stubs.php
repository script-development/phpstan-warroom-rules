<?php

declare(strict_types = 1);

// Stub classes referenced by RawExceptionMessageInResponse fixtures. The rule
// scopes detection on:
//   - Sink match: a `FQCN::method` signature, matched as a static call whose
//     resolved class equals the FQCN (`Laravel\Mcp\Response::error`) OR an
//     instance call whose receiver type is a subtype of the FQCN (a configured
//     persist sink).
//   - Argument shape: an argument that is (directly / via string concat) a
//     `Throwable::getMessage()` call, or a `Throwable` expression itself.
//   - Logger/report exclusion: a `Log::` / `logger()->` / PSR `LoggerInterface`
//     log-level call, or `report()`, is never a leak.
//
// composer.json requires none of laravel/mcp, psr/log, or illuminate/support,
// so the sink base, the PSR logger contract, the Log facade, the `logger()` /
// `report()` helpers, and a non-Throwable object carrying a `getMessage()`
// method are stubbed here with their real FQCNs — enough for PHPStan's FQCN
// resolution + subtype inference without pulling the real packages. `Throwable`
// and its exception hierarchy (`Exception`, `RuntimeException`,
// `InvalidArgumentException`) are PHP core, so fixtures use them directly with
// no stub. Mirrors the sibling rules' fixture-stub approach.

namespace Laravel\Mcp {
    // The MCP tool response. `error()` is the confirmed client-facing sink —
    // ublgenie's 8 MCP tools + codebook DeleteChapterTool all return
    // `Response::error('...' . $e->getMessage())`.
    final class Response
    {
        public static function error(string $message): self
        {
            return new self;
        }
    }
}

namespace Psr\Log {
    // Minimal PSR-3 logger contract — the instance-logger exclusion resolves a
    // receiver's subtype against this.
    interface LoggerInterface
    {
        public function error(string $message, array $context = []): void;

        public function info(string $message, array $context = []): void;
    }
}

namespace Illuminate\Support\Facades {
    // The Log facade — static log-level calls resolve against this FQCN.
    class Log
    {
        public static function error(string $message, array $context = []): void {}

        public static function info(string $message, array $context = []): void {}
    }
}

namespace App\Support {
    use Psr\Log\LoggerInterface;

    // A consumer-side PERSIST sink — records a failure message to a store. Not
    // a logger; a configured `App\Support\InvoiceLog::recordError` sink.
    final class InvoiceLog
    {
        public function recordError(string $message): void {}
    }

    // A non-Throwable object that happens to expose getMessage() — the type
    // gate must NOT treat its getMessage() as a leak.
    final class ValidatorBag
    {
        public function getMessage(): string
        {
            return 'app-authored validation summary';
        }
    }

    // A class holding an injected PSR logger, for the instance-logger exclusion.
    final class ReportsToLogger
    {
        public function __construct(
            public LoggerInterface $logger,
        ) {}
    }
}

namespace {
    use Psr\Log\LoggerInterface;

    // The `logger()` helper — normally in illuminate/foundation's helpers file.
    if (!\function_exists('logger')) {
        function logger(): LoggerInterface
        {
            return new class implements LoggerInterface {
                public function error(string $message, array $context = []): void {}

                public function info(string $message, array $context = []): void {}
            };
        }
    }

    // The `report()` helper — normally in illuminate/foundation's helpers file.
    if (!\function_exists('report')) {
        function report(Throwable $throwable): void {}
    }
}
