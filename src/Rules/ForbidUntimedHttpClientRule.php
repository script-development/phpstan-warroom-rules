<?php

declare(strict_types = 1);

namespace ScriptDevelopment\PhpstanWarroomRules\Rules;

use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Http;
use PhpParser\Node;
use PhpParser\Node\Expr\Array_;
use PhpParser\Node\Expr\CallLike;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\Scalar\String_;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\IdentifierRuleError;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;
use PHPStan\Type\ObjectType;
use PHPStan\Type\Type;

use function in_array;
use function mb_strtolower;

/**
 * Forbids an outbound HTTP request built on Laravel's HTTP client that reaches
 * a terminal send verb (`get` / `post` / `put` / `patch` / `delete` / `head` /
 * `send`) without an explicit request timeout somewhere in the fluent chain.
 *
 * Two entry points are recognised, because both are in live fleet use:
 *
 *   1. The `Http` facade (`Illuminate\Support\Facades\Http`) — a static-call
 *      root, e.g. `Http::withToken($t)->get($url)`.
 *   2. An injected client factory (`Illuminate\Http\Client\Factory`) — the
 *      dominant fleet idiom, e.g. `$this->http->withToken($t)->get($url)`
 *      where `$this->http` is a promoted `Factory` property. Anchored by TYPE,
 *      so the alias (`$http` / `$httpClient` / `$client`) is irrelevant.
 *
 * A timeout is considered present when the visible chain contains a
 * `->timeout(...)` call OR a `->withOptions([... 'timeout' => ... ])` carrying
 * a `'timeout'` key. `connectTimeout()` alone is NOT sufficient — it bounds the
 * handshake, not the response, so a hung server still stalls the caller;
 * Doctrine #8 wants the request timeout.
 *
 * Doctrine source: war-room §Architectural Principles #8 — Explicit timeouts
 * on external HTTP calls. Promotion candidate for war-room enforcement queue
 * #58 (the AST-aware successor to the per-territory `ExternalHttpTimeoutTest`
 * named-list Pest tests on kendo / emmie, which detect wrong-shape on enrolled
 * classes but are blind to OMISSION — a new untimed call nobody enrolls).
 *
 * CONSERVATIVE BY DESIGN — fires only when the ENTIRE chain from an entry point
 * (facade static call, or a `Factory`-typed receiver) to the send verb is
 * visible in a single expression. It deliberately DECLINES (never a false
 * positive) on:
 *
 *   - Split chains — the `PendingRequest` is built on one statement / helper
 *     and sent on another (`$req = $this->http->timeout(5); $req->get(...)`, or
 *     `$this->apiClient()->post(...)` where `apiClient()` returns a pre-timed
 *     request). The send-site expression cannot see the builder, so a
 *     `PendingRequest`-typed (as opposed to `Factory`-typed) root is NOT an
 *     anchor — the timeout may have been set upstream.
 *   - Raw `GuzzleHttp\Client` construction / per-call `['timeout' => N]` request
 *     options (a distinct AST surface — the timeout rides an options array).
 *   - Vendor SDKs that wrap Guzzle and configure the timeout via their own
 *     `setConfig([...])` — invisible to any HTTP-client scan; flagging them
 *     would be a false positive.
 *   - Timeouts configured at a DI binding (a provider binds a pre-timed client).
 *
 * These exclusions are the deliberate consequence of biasing a first-cut rule
 * toward zero false positives (a false positive reddens a compliant consumer's
 * whole `phpstan` run and forces a coordinated baseline; a false negative is
 * absorbed by the surviving named-list test). The excluded surfaces remain the
 * responsibility of the per-territory named-list Pest test until a follow-up
 * widens this rule.
 *
 * Suppression: standard PHPStan inline-ignore on the identifier
 * `forbidUntimedHttpClient.missingTimeout`.
 *
 * @implements Rule<CallLike>
 */
final class ForbidUntimedHttpClientRule implements Rule
{
    private const string HTTP_FACADE = Http::class;

    private const string CLIENT_FACTORY = Factory::class;

    /** Terminal HTTP send verbs on the facade / factory / `PendingRequest`. */
    private const array SEND_VERBS = ['get', 'post', 'put', 'patch', 'delete', 'head', 'send'];

    public function getNodeType(): string
    {
        return CallLike::class;
    }

    public function processNode(Node $node, Scope $scope): array
    {
        if ($node instanceof StaticCall) {
            return $this->processStaticSend($node);
        }

        if ($node instanceof MethodCall) {
            return $this->processChainedSend($node, $scope);
        }

        return [];
    }

    /**
     * A bare static send with no chain at all — `Http::get($url)` — can carry
     * no timeout, so it is always a violation when rooted at the Http facade.
     *
     * @return list<IdentifierRuleError>
     */
    private function processStaticSend(StaticCall $node): array
    {
        if (!$this->isHttpFacade($node->class) || !$this->isSendVerb($node->name)) {
            return [];
        }

        return [$this->buildError($node)];
    }

    /**
     * A chained send — `<entry>->...->get($url)`. Fires only when the receiver
     * chain is fully visible back to a recognised entry point (the `Http`
     * facade static call, or a `Factory`-typed receiver) AND carries no
     * timeout. Any other / unresolved root ⇒ DECLINE (return []).
     *
     * @return list<IdentifierRuleError>
     */
    private function processChainedSend(MethodCall $node, Scope $scope): array
    {
        if (!$this->isSendVerb($node->name)) {
            return [];
        }

        $cursor = $node->var;

        while ($cursor instanceof MethodCall) {
            if ($this->declaresTimeout($cursor)) {
                return [];
            }

            $cursor = $cursor->var;
        }

        if ($cursor instanceof StaticCall) {
            // Chain root is a static call. Only the Http facade is an entry we
            // can fully account for; the root call may itself set the timeout.
            if (!$this->isHttpFacade($cursor->class)) {
                return [];
            }

            return $this->declaresTimeout($cursor) ? [] : [$this->buildError($node)];
        }

        // Chain root is an expression (property / variable). It anchors ONLY if
        // its type is the client Factory — the entry point, where the whole
        // chain is guaranteed visible. A `PendingRequest`-typed root may carry
        // an upstream timeout we cannot see, so it is NOT an anchor.
        if ($this->isClientFactory($scope->getType($cursor))) {
            return [$this->buildError($node)];
        }

        return [];
    }

    /**
     * True when a builder call in the chain establishes a request timeout:
     * a `->timeout(...)` method, or a `->withOptions([... 'timeout' => ...])`
     * carrying the option key.
     */
    private function declaresTimeout(MethodCall|StaticCall $call): bool
    {
        $name = $this->methodName($call->name);

        if ($name === 'timeout') {
            return true;
        }

        if ($name === 'withoptions') {
            return $this->argArrayHasTimeoutKey($call);
        }

        return false;
    }

    private function argArrayHasTimeoutKey(MethodCall|StaticCall $call): bool
    {
        $first = $call->getArgs()[0]->value ?? null;

        if (!$first instanceof Array_) {
            return false;
        }

        foreach ($first->items as $item) {
            if ($item->key instanceof String_ && mb_strtolower($item->key->value) === 'timeout') {
                return true;
            }
        }

        return false;
    }

    private function isClientFactory(Type $type): bool
    {
        return (new ObjectType(self::CLIENT_FACTORY))->isSuperTypeOf($type)->yes();
    }

    private function isHttpFacade(mixed $class): bool
    {
        return $class instanceof Name && $class->toString() === self::HTTP_FACADE;
    }

    private function isSendVerb(mixed $name): bool
    {
        return in_array($this->methodName($name), self::SEND_VERBS, true);
    }

    private function methodName(mixed $name): string
    {
        return $name instanceof Identifier ? mb_strtolower($name->toString()) : '';
    }

    private function buildError(MethodCall|StaticCall $node): IdentifierRuleError
    {
        return RuleErrorBuilder::message(
            'Outbound HTTP request declares no explicit timeout. '
            . 'Add ->timeout(seconds) to the chain — external calls must not rely on the framework default (Doctrine Principle #8).',
        )
            ->identifier('forbidUntimedHttpClient.missingTimeout')
            ->line($node->getStartLine())
            ->build();
    }
}
