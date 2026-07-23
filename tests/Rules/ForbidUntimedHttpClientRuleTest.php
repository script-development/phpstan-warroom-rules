<?php

declare(strict_types = 1);

namespace ScriptDevelopment\PhpstanWarroomRules\Tests\Rules;

use PHPStan\Rules\Rule;
use PHPStan\Testing\RuleTestCase;
use ScriptDevelopment\PhpstanWarroomRules\Rules\ForbidUntimedHttpClientRule;

/**
 * @extends RuleTestCase<ForbidUntimedHttpClientRule>
 */
final class ForbidUntimedHttpClientRuleTest extends RuleTestCase
{
    private const string MESSAGE = 'Outbound HTTP request declares no explicit timeout. '
        . 'Add ->timeout(seconds) to the chain — external calls must not rely on the framework default (Doctrine Principle #8).';

    public function testFlagsBareStaticSend(): void
    {
        $this->analyse(
            [__DIR__ . '/../Fixtures/UntimedHttpClient/ViolationBareStaticGet.php'],
            [
                [self::MESSAGE, 13],
            ],
        );
    }

    public function testFlagsChainWithoutTimeout(): void
    {
        $this->analyse(
            [__DIR__ . '/../Fixtures/UntimedHttpClient/ViolationChainNoTimeout.php'],
            [
                [self::MESSAGE, 13],
            ],
        );
    }

    public function testFlagsConnectTimeoutOnly(): void
    {
        // connectTimeout() is not a request timeout — the rule must still fire.
        $this->analyse(
            [__DIR__ . '/../Fixtures/UntimedHttpClient/ViolationConnectTimeoutOnly.php'],
            [
                [self::MESSAGE, 15],
            ],
        );
    }

    public function testFlagsInjectedFactoryChainWithoutTimeout(): void
    {
        // The dominant fleet idiom: an injected Illuminate\Http\Client\Factory.
        $this->analyse(
            [__DIR__ . '/../Fixtures/UntimedHttpClient/ViolationFactoryChainNoTimeout.php'],
            [
                [self::MESSAGE, 17],
            ],
        );
    }

    public function testIgnoresInjectedFactoryChainWithTimeout(): void
    {
        $this->analyse(
            [__DIR__ . '/../Fixtures/UntimedHttpClient/CompliantFactoryChainTimeout.php'],
            [],
        );
    }

    public function testDeclinesLocalPendingRequestVariable(): void
    {
        // Timeout set upstream on a PendingRequest-typed local — out of view.
        $this->analyse(
            [__DIR__ . '/../Fixtures/UntimedHttpClient/DeclinedLocalPendingRequestVar.php'],
            [],
        );
    }

    public function testIgnoresStaticTimeoutChain(): void
    {
        $this->analyse(
            [__DIR__ . '/../Fixtures/UntimedHttpClient/CompliantTimeoutStatic.php'],
            [],
        );
    }

    public function testIgnoresTimeoutMidChain(): void
    {
        $this->analyse(
            [__DIR__ . '/../Fixtures/UntimedHttpClient/CompliantTimeoutMidChain.php'],
            [],
        );
    }

    public function testIgnoresWithOptionsTimeoutKey(): void
    {
        $this->analyse(
            [__DIR__ . '/../Fixtures/UntimedHttpClient/CompliantWithOptionsTimeout.php'],
            [],
        );
    }

    public function testDeclinesSplitChainBuiltOnAProperty(): void
    {
        // Builder assembled out of view — deliberate conservative miss.
        $this->analyse(
            [__DIR__ . '/../Fixtures/UntimedHttpClient/DeclinedSplitChainProperty.php'],
            [],
        );
    }

    public function testIgnoresNonHttpReceiver(): void
    {
        $this->analyse(
            [__DIR__ . '/../Fixtures/UntimedHttpClient/IgnoredNonHttpReceiver.php'],
            [],
        );
    }

    public function testDeclinesComputedWithOptions(): void
    {
        // The #57 review's Major: a computed options array (helper return) is
        // not a constant array type — absence of 'timeout' is unprovable, so
        // the chain DECLINES instead of false-positive flagging.
        $this->analyse(
            [__DIR__ . '/../Fixtures/UntimedHttpClient/DeclinedWithOptionsComputed.php'],
            [],
        );
    }

    public function testFlagsWithOptionsVariableProvablyWithoutTimeout(): void
    {
        // The type path sees through a variable holding a literal array — a
        // provably timeout-free options set still fires (a widening over the
        // old inline-Array_-only check).
        $this->analyse(
            [__DIR__ . '/../Fixtures/UntimedHttpClient/ViolationWithOptionsVariableNoTimeout.php'],
            [[self::MESSAGE, 19]],
        );
    }

    public function testIgnoresWithOptionsVariableWithTimeout(): void
    {
        $this->analyse(
            [__DIR__ . '/../Fixtures/UntimedHttpClient/CompliantWithOptionsVariableTimeout.php'],
            [],
        );
    }

    public function testDeclinesMacroStaticRoot(): void
    {
        // The #57 review's Minor: `Http::github()` — a Macroable entry outside
        // the known builder surface — may return a pre-timed request. Declines.
        $this->analyse(
            [__DIR__ . '/../Fixtures/UntimedHttpClient/DeclinedMacroStaticRoot.php'],
            [],
        );
    }

    public function testDeclinesMacroMidChain(): void
    {
        // Same guard for an intermediate member — PendingRequest is Macroable.
        $this->analyse(
            [__DIR__ . '/../Fixtures/UntimedHttpClient/DeclinedMacroMidChain.php'],
            [],
        );
    }

    protected function getRule(): Rule
    {
        return new ForbidUntimedHttpClientRule;
    }
}
