<?php

declare(strict_types = 1);

namespace ScriptDevelopment\PhpstanWarroomRules\Tests\Rules;

use PHPStan\Rules\Rule;
use PHPStan\Testing\RuleTestCase;
use ScriptDevelopment\PhpstanWarroomRules\Rules\ForbidRawExceptionMessageInResponseRule;

/**
 * @extends RuleTestCase<ForbidRawExceptionMessageInResponseRule>
 */
final class ForbidRawExceptionMessageInResponseRuleTest extends RuleTestCase
{
    private const string MESSAGE = 'Raw exception message reaches a client-facing response sink. '
        . 'Passing Throwable::getMessage() (or the Throwable itself) to a response leaks internal detail '
        . '(stack-trace fragments, SQL, file paths) to the API client. Log the raw message server-side '
        . '(Log::/report()) and return a stable, app-authored message. '
        . 'Suppress a proven-safe app-authored message with a `// @leak-safe: <rationale>` comment on the sink line, '
        . 'or list an arch-test-pinned exception class in `safeMessageExceptionClasses`.';

    private const string STUBS = __DIR__ . '/../Fixtures/RawExceptionMessageInResponse/_stubs.php';

    /**
     * Override hook: when set, `getRule()` returns this instance instead of the
     * default. Lets a single test reconfigure `rawExceptionMessageSinks`.
     */
    private ?Rule $ruleOverride = null;

    public function testFlagsConcatGetMessageIntoResponseError(): void
    {
        // The dominant MCP-tool shape: Response::error('x: ' . $e->getMessage()).
        $this->analyse(
            [self::STUBS, __DIR__ . '/../Fixtures/RawExceptionMessageInResponse/ErrorSinkConcatGetMessage.php'],
            [[self::MESSAGE, 16]],
        );
    }

    public function testFlagsDirectGetMessageIntoResponseError(): void
    {
        $this->analyse(
            [self::STUBS, __DIR__ . '/../Fixtures/RawExceptionMessageInResponse/ErrorSinkDirectGetMessage.php'],
            [[self::MESSAGE, 16]],
        );
    }

    public function testFlagsThrowableItselfIntoSink(): void
    {
        $this->analyse(
            [self::STUBS, __DIR__ . '/../Fixtures/RawExceptionMessageInResponse/ThrowableItselfIntoSink.php'],
            [[self::MESSAGE, 16]],
        );
    }

    public function testFlagsConfiguredPersistSink(): void
    {
        // The persist sink fires ONLY when its signature is configured.
        $this->ruleOverride = new ForbidRawExceptionMessageInResponseRule(
            ['App\Support\InvoiceLog::recordError'],
        );

        $this->analyse(
            [self::STUBS, __DIR__ . '/../Fixtures/RawExceptionMessageInResponse/PersistSinkGetMessage.php'],
            [[self::MESSAGE, 21]],
        );
    }

    public function testIgnoresPersistSinkWhenNotConfigured(): void
    {
        // Same fixture under the default config — the persist sink is not armed,
        // so the raw message flowing into it is silent. Pins "empty param =
        // only Response::error built-in".
        $this->analyse(
            [self::STUBS, __DIR__ . '/../Fixtures/RawExceptionMessageInResponse/PersistSinkGetMessage.php'],
            [],
        );
    }

    public function testIgnoresLoggingOfRawMessage(): void
    {
        // Log::error / logger()->error / report() — the remediation, never a leak.
        $this->analyse(
            [self::STUBS, __DIR__ . '/../Fixtures/RawExceptionMessageInResponse/LogsGetMessage.php'],
            [],
        );
    }

    public function testIgnoresAppAuthoredLiteralMessage(): void
    {
        $this->analyse(
            [self::STUBS, __DIR__ . '/../Fixtures/RawExceptionMessageInResponse/AppAuthoredLiteralMessage.php'],
            [],
        );
    }

    public function testIgnoresLeakSafeExemptedSink(): void
    {
        // `// @leak-safe:` marker in the comment block above the sink call.
        $this->analyse(
            [self::STUBS, __DIR__ . '/../Fixtures/RawExceptionMessageInResponse/LeakSafeExempted.php'],
            [],
        );
    }

    public function testIgnoresGetMessageOnNonThrowable(): void
    {
        // getMessage() on a non-Throwable receiver — the type gate keeps it silent.
        $this->analyse(
            [self::STUBS, __DIR__ . '/../Fixtures/RawExceptionMessageInResponse/NonThrowableGetMessage.php'],
            [],
        );
    }

    public function testIgnoresInstancePsrLoggerUnderDefaultConfig(): void
    {
        $this->analyse(
            [self::STUBS, __DIR__ . '/../Fixtures/RawExceptionMessageInResponse/PsrLoggerGetMessage.php'],
            [],
        );
    }

    public function testLoggerExclusionOverridesAConfiguredLoggerSink(): void
    {
        // Even if a consumer (mis)configures a logger method as a sink, the
        // logger/report exclusion short-circuits first — server-side logging is
        // never criminalized. Gives the mandatory exclusion real teeth.
        $this->ruleOverride = new ForbidRawExceptionMessageInResponseRule(
            ['Psr\Log\LoggerInterface::error'],
        );

        $this->analyse(
            [self::STUBS, __DIR__ . '/../Fixtures/RawExceptionMessageInResponse/PsrLoggerGetMessage.php'],
            [],
        );
    }

    public function testStaticLoggerExclusionOverridesAConfiguredLogFacadeSink(): void
    {
        // Configure the Log facade method AS a sink; the static-logger exclusion
        // must still hold `Log::error($e->getMessage())` silent. Gives the
        // static-call branch of the exclusion real teeth.
        $this->ruleOverride = new ForbidRawExceptionMessageInResponseRule(
            ['Illuminate\Support\Facades\Log::error'],
        );

        $this->analyse(
            [self::STUBS, __DIR__ . '/../Fixtures/RawExceptionMessageInResponse/LogFacadeDirectGetMessage.php'],
            [],
        );
    }

    public function testHelperLoggerExclusionOverridesAConfiguredLoggerSink(): void
    {
        // Configure the PSR logger method AS a sink; `logger()->error(...)` (the
        // helper's return type IS LoggerInterface, so it would match the sink)
        // must still be held silent by the logger()-helper exclusion branch.
        $this->ruleOverride = new ForbidRawExceptionMessageInResponseRule(
            ['Psr\Log\LoggerInterface::error'],
        );

        $this->analyse(
            [self::STUBS, __DIR__ . '/../Fixtures/RawExceptionMessageInResponse/LoggerHelperGetMessage.php'],
            [],
        );
    }

    public function testIgnoresSameLineLeakSafeMarker(): void
    {
        // `// @leak-safe:` trailing comment on the sink call line itself.
        $this->analyse(
            [self::STUBS, __DIR__ . '/../Fixtures/RawExceptionMessageInResponse/LeakSafeSameLineExempted.php'],
            [],
        );
    }

    public function testFlagsNullsafeGetMessageIntoResponseError(): void
    {
        // `$e?->getMessage()` is a NullsafeMethodCall — a distinct AST node the
        // MethodCall-only matcher missed (the #59 review's Nit). Same leak.
        $this->analyse(
            [self::STUBS, __DIR__ . '/../Fixtures/RawExceptionMessageInResponse/NullsafeGetMessage.php'],
            [[self::MESSAGE, 17]],
        );
    }

    public function testFlagsSafeMessageExceptionUnderDefaultConfig(): void
    {
        // No allowlist configured — a prove-safe class is still an exception
        // like any other. Pins "empty param = no class-level exemption".
        $this->analyse(
            [self::STUBS, __DIR__ . '/../Fixtures/RawExceptionMessageInResponse/SafeMessageExceptionPassthrough.php'],
            [[self::MESSAGE, 18]],
        );
    }

    public function testIgnoresConfiguredSafeMessageExceptionGetMessage(): void
    {
        // The #59 review's Major: the rule's own motivating example (codebook
        // DeleteChapterTool passing DependentModelRelationException::getMessage(),
        // arch-test-pinned app-authored) needed a config-level exemption, not a
        // per-call-site annotation. Type-aware: subtypes inherit the allowance.
        $this->ruleOverride = new ForbidRawExceptionMessageInResponseRule(
            [],
            ['App\Exceptions\DependentModelRelationException'],
        );

        $this->analyse(
            [self::STUBS, __DIR__ . '/../Fixtures/RawExceptionMessageInResponse/SafeMessageExceptionPassthrough.php'],
            [],
        );
    }

    public function testSafeMessageExceptionDoesNotExemptTheThrowableItself(): void
    {
        // The allowlist covers the MESSAGE only — the Throwable stringifies
        // with class, file, and trace regardless of message discipline.
        $this->ruleOverride = new ForbidRawExceptionMessageInResponseRule(
            [],
            ['App\Exceptions\DependentModelRelationException'],
        );

        $this->analyse(
            [self::STUBS, __DIR__ . '/../Fixtures/RawExceptionMessageInResponse/SafeMessageExceptionThrowableItself.php'],
            [[self::MESSAGE, 17]],
        );
    }

    public function testMalformedSinkSignaturesAreSkippedNotFatal(): void
    {
        // Garbage sink signatures (no `::`, too many `::`, empty class, empty
        // method) are skipped by the signature parser — they neither crash nor
        // arm a bogus sink, and the always-armed Response::error default still
        // fires. Pins the parser's guard.
        $this->ruleOverride = new ForbidRawExceptionMessageInResponseRule(
            ['NoSeparatorHere', 'A::b::c', '::emptyClass', 'EmptyMethod::'],
        );

        $this->analyse(
            [self::STUBS, __DIR__ . '/../Fixtures/RawExceptionMessageInResponse/ErrorSinkConcatGetMessage.php'],
            [[self::MESSAGE, 16]],
        );
    }

    public function testRuleResolvesFromExtensionNeonAndFiresOnDefaultSink(): void
    {
        // End-to-end pin on the extension.neon registration path consumers
        // actually use: resolve the rule from the PHPStan container so the
        // shipped `rawExceptionMessageSinks` default ([]) + the built-in
        // Response::error sink wiring are exercised — NOT the PHP constructor
        // default. A NEON regression would silently no-op the rule; this asserts
        // the canonical Response::error leak still flags under the shipped wiring.
        $this->ruleOverride = self::getContainer()->getByType(ForbidRawExceptionMessageInResponseRule::class);

        $this->analyse(
            [self::STUBS, __DIR__ . '/../Fixtures/RawExceptionMessageInResponse/ErrorSinkConcatGetMessage.php'],
            [[self::MESSAGE, 16]],
        );
    }

    /**
     * Load the shipped extension.neon so the container-resolved test can pull
     * the rule out with its NEON-configured `rawExceptionMessageSinks`.
     *
     * @return array<int, string>
     */
    public static function getAdditionalConfigFiles(): array
    {
        return [
            __DIR__ . '/../../extension.neon',
        ];
    }

    protected function getRule(): Rule
    {
        return $this->ruleOverride ?? new ForbidRawExceptionMessageInResponseRule;
    }
}
