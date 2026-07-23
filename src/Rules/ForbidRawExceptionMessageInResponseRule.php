<?php

declare(strict_types = 1);

namespace ScriptDevelopment\PhpstanWarroomRules\Rules;

use Illuminate\Support\Facades\Log;
use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\BinaryOp\Concat;
use PhpParser\Node\Expr\CallLike;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\NullsafeMethodCall;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\IdentifierRuleError;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;
use PHPStan\Type\NeverType;
use PHPStan\Type\ObjectType;
use PHPStan\Type\Type;
use PHPStan\Type\TypeCombinator;
use Psr\Log\LoggerInterface;
use Throwable;

use function count;
use function explode;
use function file_get_contents;
use function in_array;
use function is_file;
use function mb_trim;
use function str_contains;
use function str_starts_with;

/**
 * Forbids a raw `Throwable::getMessage()` — or a `Throwable` expression itself —
 * flowing into a CLIENT-FACING response sink. A raw exception message is
 * internal detail: stack-trace fragments, SQL, file paths, driver errors. When
 * it reaches an API response it becomes an information-disclosure leak (ISO
 * 27001 A.5.33 / general defence-in-depth). The remediation is always the same:
 * LOG the raw message server-side (`Log::`, `report()`) and hand the client a
 * stable, app-authored message.
 *
 * Doctrine source: war-room §Architectural Principles — Explicit over implicit
 * (#1); information-disclosure hardening for the ISO 27001 / AVG / NEN 7510
 * consumer territories (kendo, entreezuil, ublgenie, emmie, codebook).
 *
 * The dominant confirmed shape is the Laravel-MCP tool that returns
 * `Response::error('...: ' . $e->getMessage())` (ublgenie's 8 MCP tools +
 * codebook `DeleteChapterTool` — every one concatenates the raw message into
 * the error response). `Laravel\Mcp\Response::error` is therefore the built-in
 * default sink; a consumer adds its own PERSIST sinks (an invoice-log setter,
 * a `MarkInvoiceFailed` Action) via the `rawExceptionMessageSinks` parameter,
 * default `[]`, so the rule is safe to adopt with only the MCP shape armed.
 *
 * A sink is a `FQCN::method` signature. It is matched in BOTH call forms:
 *
 *   - a `StaticCall` whose resolved class equals the sink FQCN
 *     (`Response::error(...)`), and
 *   - a `MethodCall` whose receiver type is a subtype of the sink FQCN
 *     (`$this->response->error(...)`, an injected persist-sink service).
 *
 * A matched sink call is flagged when ANY argument is, directly OR via string
 * concatenation (`'context: ' . $e->getMessage()`):
 *
 *   - a `->getMessage()` `MethodCall` on an expression whose type is a subtype
 *     of `\Throwable`, or
 *   - a `\Throwable` expression passed directly into the sink.
 *
 * Type-aware discrimination is load-bearing: `$validator->getMessage()` (a
 * non-`Throwable` receiver) does NOT fire — only a message pulled off an actual
 * exception is a leak.
 *
 * NEVER flagged (mandatory false-positive exclusions — the remediation pattern,
 * not the violation):
 *
 *   - `Log::` / `logger()->` / PSR `LoggerInterface` log-level calls
 *     (`info` / `warning` / `error` / `critical` / `debug` / `log` / `notice`
 *     / `alert` / `emergency`) and `report()`. Server-side logging of the raw
 *     message is CORRECT — it is where the raw message is *supposed* to go.
 *     Because a sink is keyed on `FQCN::method`, a logger is never a sink under
 *     the default config; this exclusion additionally short-circuits BEFORE
 *     sink matching, so a consumer that adds a broad sink can never turn a
 *     logger into a false positive.
 *
 * Exemptions, narrowest first:
 *
 *   - `safeMessageExceptionClasses` (config, the CLASS-level path): exception
 *     FQCNs whose messages are proven app-authored (the codebook
 *     `DependentModelRelationException` shape — its message discipline is
 *     pinned by an arch test in the consuming territory). A `getMessage()`
 *     whose receiver is a subtype of a listed class never fires, so a
 *     prove-safe class needs ONE config line, not an annotation at every call
 *     site. The allowlist covers the MESSAGE only — passing the Throwable
 *     itself into a sink still fires (`__toString` carries class, file, and
 *     trace regardless of message discipline). List a class here only when the
 *     consuming territory pins its message discipline with an arch test;
 *     config without the pin is a hole, not an exemption.
 *   - `// @leak-safe: <rationale>` comment on the sink call line (or in the
 *     contiguous comment block directly above it) — the per-call-site path for
 *     a proven-safe case the class-level list cannot express (the codebook
 *     `SendCodyReportAction` shape). The standard PHPStan inline-ignore on the
 *     identifier `forbidRawExceptionMessageInResponse.rawMessageInResponse` is
 *     the alternative.
 *
 * Out of scope (deliberately, for v1):
 *
 *   - `getTraceAsString()` / `__toString()` / other Throwable accessors — the
 *     confirmed leak surface is `getMessage()` and the Throwable itself; a
 *     future minor can widen the accessor set.
 *   - Sinks passed a Throwable through a helper/formatter call
 *     (`Response::error($this->format($e))`) — the type at the sink boundary is
 *     the formatter's return, not a Throwable; a false negative is accepted
 *     (ADR-0021 posture: false negatives acceptable, false positives are not).
 *   - Plain local-variable extraction (`$msg = $e->getMessage();
 *     Response::error($msg);`) — the mundane sibling of the formatter gap: the
 *     type at the sink is `string`, the provenance is gone. Same accepted-
 *     false-negative posture; closing it needs data-flow tracking, not a
 *     wider matcher.
 *
 * @implements Rule<CallLike>
 */
final class ForbidRawExceptionMessageInResponseRule implements Rule
{
    /**
     * Built-in default sink — the Laravel-MCP `Response::error(...)` shape that
     * dominates the confirmed leak surface. Always armed; consumer-configured
     * sinks are added to it, never replace it.
     */
    private const string DEFAULT_SINK = 'Laravel\Mcp\Response::error';

    private const string THROWABLE = Throwable::class;

    /** Log-level method names — a call to any of these on a logger is the remediation, never a leak. */
    private const array LOGGER_METHODS = [
        'info', 'warning', 'error', 'critical', 'debug', 'log', 'notice', 'alert', 'emergency',
    ];

    /** Logger receivers whose log-level calls are excluded. */
    private const string LOG_FACADE = Log::class;

    private const string PSR_LOGGER = LoggerInterface::class;

    private const string LOGGER_HELPER = 'logger';

    /**
     * Parsed sink signatures — each `['class' => FQCN, 'method' => name]`.
     *
     * @var list<array{class: string, method: string}>
     */
    private array $sinks;

    /**
     * @param list<string> $rawExceptionMessageSinks    additional client-facing
     *                                                  sink signatures in
     *                                                  `FQCN::method` form (e.g. a
     *                                                  consumer's persist-error
     *                                                  setter). Merged with the
     *                                                  built-in `Response::error`
     *                                                  default; empty by default so
     *                                                  the rule is safe to adopt.
     * @param list<string> $safeMessageExceptionClasses exception FQCNs whose
     *                                                  messages are proven
     *                                                  app-authored (arch-test-
     *                                                  pinned in the consuming
     *                                                  territory) — their
     *                                                  `getMessage()` is exempt;
     *                                                  the Throwable itself never
     *                                                  is. Empty by default.
     */
    public function __construct(
        array $rawExceptionMessageSinks = [],
        private readonly array $safeMessageExceptionClasses = [],
    ) {
        $this->sinks = [];

        foreach ([self::DEFAULT_SINK, ...$rawExceptionMessageSinks] as $signature) {
            $parsed = $this->parseSinkSignature($signature);

            if ($parsed !== null) {
                $this->sinks[] = $parsed;
            }
        }
    }

    public function getNodeType(): string
    {
        return CallLike::class;
    }

    public function processNode(Node $node, Scope $scope): array
    {
        if (!$node instanceof StaticCall && !$node instanceof MethodCall) {
            return [];
        }

        // Mandatory exclusion — a logger call is the remediation, not a leak.
        // Short-circuits before sink matching so a broad consumer sink can never
        // criminalize server-side logging. (`report()` is a FuncCall, never
        // examined here, so it is structurally out of scope already.)
        if ($this->isLoggerCall($node, $scope)) {
            return [];
        }

        if (!$this->isConfiguredSink($node, $scope)) {
            return [];
        }

        if ($this->hasLeakSafeMarker($node, $scope)) {
            return [];
        }

        foreach ($node->getArgs() as $arg) {
            if ($this->exprCarriesRawExceptionMessage($arg->value, $scope)) {
                return [$this->buildError($node)];
            }
        }

        return [];
    }

    /**
     * @return array{class: string, method: string}|null
     */
    private function parseSinkSignature(string $signature): ?array
    {
        $parts = explode('::', $signature);

        if (count($parts) !== 2 || $parts[0] === '' || $parts[1] === '') {
            return null;
        }

        return ['class' => $parts[0], 'method' => $parts[1]];
    }

    /**
     * True when the call matches a configured sink in either form: a static
     * call whose resolved class equals the sink FQCN, or an instance call whose
     * receiver type is a subtype of the sink FQCN. Method name must match.
     */
    private function isConfiguredSink(MethodCall|StaticCall $node, Scope $scope): bool
    {
        if (!$node->name instanceof Identifier) {
            return false;
        }

        $methodName = $node->name->toString();

        foreach ($this->sinks as $sink) {
            if ($sink['method'] !== $methodName) {
                continue;
            }

            if ($node instanceof StaticCall) {
                if ($node->class instanceof Name && $scope->resolveName($node->class) === $sink['class']) {
                    return true;
                }

                continue;
            }

            if ((new ObjectType($sink['class']))->isSuperTypeOf($scope->getType($node->var))->yes()) {
                return true;
            }
        }

        return false;
    }

    /**
     * True when `$expr` is, directly or through string concatenation, a raw
     * exception message: a `Throwable::getMessage()` call or a `Throwable`
     * expression itself.
     */
    private function exprCarriesRawExceptionMessage(Expr $expr, Scope $scope): bool
    {
        if ($expr instanceof Concat) {
            return $this->exprCarriesRawExceptionMessage($expr->left, $scope)
                || $this->exprCarriesRawExceptionMessage($expr->right, $scope);
        }

        if ($this->isThrowableGetMessageCall($expr, $scope)) {
            return true;
        }

        // The Throwable itself passed into the sink (a `getMessage()` call
        // returns string, so this branch never double-counts the call above).
        return $this->typeIsThrowable($scope->getType($expr));
    }

    private function isThrowableGetMessageCall(Expr $expr, Scope $scope): bool
    {
        // NullsafeMethodCall is a distinct node — `$e?->getMessage()` leaks
        // exactly as its unconditional sibling does when `$e` is non-null.
        if (!$expr instanceof MethodCall && !$expr instanceof NullsafeMethodCall) {
            return false;
        }

        if (!$expr->name instanceof Identifier || $expr->name->toString() !== 'getMessage') {
            return false;
        }

        $receiverType = $scope->getType($expr->var);

        return $this->typeIsThrowable($receiverType)
            && !$this->isSafeMessageException($receiverType);
    }

    /**
     * True when the receiver's type is a subtype of a configured
     * safe-message exception class — its `getMessage()` is proven
     * app-authored, so the message (and ONLY the message) is exempt.
     */
    private function isSafeMessageException(Type $type): bool
    {
        $type = TypeCombinator::removeNull($type);

        if ($type instanceof NeverType) {
            return false;
        }

        foreach ($this->safeMessageExceptionClasses as $class) {
            if ((new ObjectType($class))->isSuperTypeOf($type)->yes()) {
                return true;
            }
        }

        return false;
    }

    private function typeIsThrowable(Type $type): bool
    {
        // Strip null so a nullsafe receiver (`$e?->getMessage()` — the receiver
        // types as `Throwable|null` inside the NullsafeMethodCall) still
        // resolves; a pure-null type (NeverType after the strip) never is one.
        $type = TypeCombinator::removeNull($type);

        if ($type instanceof NeverType) {
            return false;
        }

        return (new ObjectType(self::THROWABLE))->isSuperTypeOf($type)->yes();
    }

    /**
     * Recognise a logger call so it is never treated as a leak. Covers
     * `Log::error(...)`, `logger()->error(...)`, and a PSR `LoggerInterface`
     * instance call. (`report(...)` is a FuncCall, already out of scope.).
     */
    private function isLoggerCall(MethodCall|StaticCall $node, Scope $scope): bool
    {
        if (!$node->name instanceof Identifier) {
            return false;
        }

        $methodName = $node->name->toString();

        if ($node instanceof StaticCall) {
            return in_array($methodName, self::LOGGER_METHODS, true)
                && $node->class instanceof Name
                && $scope->resolveName($node->class) === self::LOG_FACADE;
        }

        if (!in_array($methodName, self::LOGGER_METHODS, true)) {
            return false;
        }

        // `logger()->error(...)` — receiver is the `logger()` helper FuncCall.
        if (
            $node->var instanceof FuncCall
            && $node->var->name instanceof Name
            && $node->var->name->toString() === self::LOGGER_HELPER
        ) {
            return true;
        }

        // `$this->logger->error(...)` — receiver typed as a PSR logger.
        return (new ObjectType(self::PSR_LOGGER))->isSuperTypeOf($scope->getType($node->var))->yes();
    }

    /**
     * Honour a `// @leak-safe: <rationale>` marker on the sink call line or in
     * the contiguous comment block directly above it. Mirrors
     * `EnforceAuditSnapshotOnRetryRule::hasExemptionMarker()` — PHPStan does not
     * propagate a `parent` attribute onto nodes, so the raw-source scan is the
     * reliable path.
     */
    private function hasLeakSafeMarker(MethodCall|StaticCall $node, Scope $scope): bool
    {
        foreach ($node->getComments() as $comment) {
            if (str_contains($comment->getText(), '@leak-safe')) {
                return true;
            }
        }

        $file = $scope->getFile();

        if ($file === '' || !is_file($file)) {
            return false;
        }

        $source = @file_get_contents($file);

        if ($source === false) {
            return false;
        }

        $lines = explode("\n", $source);
        $startLine = $node->getStartLine();

        // Same-line trailing comment (`Response::error(...); // @leak-safe: ...`).
        if (isset($lines[$startLine - 1]) && str_contains($lines[$startLine - 1], '@leak-safe')) {
            return true;
        }

        // Contiguous comment block immediately above the sink call.
        $idx = $startLine - 2;

        while ($idx >= 0) {
            $line = mb_trim($lines[$idx]);

            if ($line === '') {
                $idx--;

                continue;
            }

            $isCommentLine = str_starts_with($line, '//')
                || str_starts_with($line, '*')
                || str_starts_with($line, '/*');

            if (!$isCommentLine) {
                return false;
            }

            if (str_contains($line, '@leak-safe')) {
                return true;
            }

            $idx--;
        }

        return false;
    }

    private function buildError(MethodCall|StaticCall $node): IdentifierRuleError
    {
        return RuleErrorBuilder::message(
            'Raw exception message reaches a client-facing response sink. '
            . 'Passing Throwable::getMessage() (or the Throwable itself) to a response leaks internal detail '
            . '(stack-trace fragments, SQL, file paths) to the API client. Log the raw message server-side '
            . '(Log::/report()) and return a stable, app-authored message. '
            . 'Suppress a proven-safe app-authored message with a `// @leak-safe: <rationale>` comment on the sink line, '
            . 'or list an arch-test-pinned exception class in `safeMessageExceptionClasses`.',
        )
            ->identifier('forbidRawExceptionMessageInResponse.rawMessageInResponse')
            ->line($node->getStartLine())
            ->build();
    }
}
