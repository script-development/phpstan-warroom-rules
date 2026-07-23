<?php

declare(strict_types = 1);

namespace ScriptDevelopment\PhpstanWarroomRules\Rules;

use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Http;
use PhpParser\Node;
use PhpParser\Node\Expr\CallLike;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
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
 * `->timeout(...)` call OR a `->withOptions(...)` whose options provably carry
 * a `'timeout'` key. The options check is TYPE-aware, not AST-literal: a
 * variable holding a literal array resolves to a constant array type and is
 * seen through; an options expression whose type is NOT a constant array (a
 * computed array, a helper/config() return) is treated as POSSIBLY timed and
 * the chain DECLINES — absence is unprovable, and a false positive is the one
 * unacceptable outcome. `connectTimeout()` alone is NOT sufficient — it bounds
 * the handshake, not the response, so a hung server still stalls the caller;
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
 *   - `->withOptions($computed)` where the options type is not a constant
 *     array — the key set is unknowable statically (see above).
 *   - Chain members outside the known `PendingRequest` builder surface — a
 *     `Macroable` extension (`Http::github()->get(...)`, or an intermediate
 *     `->github()`) may return a PRE-TIMED request, so an unknown method
 *     anywhere in the chain (root or intermediate) declines. `when()` /
 *     `unless()` decline for the same reason: their closures can set the
 *     timeout invisibly.
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

    /**
     * Literal FQCN, not `Factory::class` — `illuminate/http` is not in this
     * package's own dev tree (only `illuminate/support` is), so a class-const
     * fetch is a `class.notFound` under self-analysis. `ObjectType` takes the
     * string happily; in a consumer tree without Laravel the anchor simply
     * never matches (correct: nothing to enforce). Same pattern as the
     * sibling rule's `DEFAULT_SINK`.
     */
    private const string CLIENT_FACTORY = Factory::class;

    /** Terminal HTTP send verbs on the facade / factory / `PendingRequest`. */
    private const array SEND_VERBS = ['get', 'post', 'put', 'patch', 'delete', 'head', 'send'];

    /**
     * The known `PendingRequest` fluent-builder surface (lowercased). A chain
     * member OUTSIDE this set — a Macroable extension, or `when()`/`unless()`
     * whose closures are opaque — may have set the timeout internally, so the
     * chain declines rather than risk a false positive. A genuine builder
     * missing from this list costs only a false negative (ADR-0021 posture).
     */
    private const array KNOWN_BUILDERS = [
        'accept', 'acceptjson', 'asform', 'asjson', 'asmultipart', 'async', 'attach',
        'baseurl', 'beforesending', 'bodyformat', 'connecttimeout', 'contenttype',
        'dd', 'dump', 'maxredirects', 'replaceheaders', 'retry', 'sink',
        'throw', 'throwif', 'throwunless', 'timeout',
        'withbasicauth', 'withbody', 'withcookies', 'withdigestauth',
        'withheader', 'withheaders', 'withmiddleware', 'withoptions',
        'withqueryparameters', 'withrequestmiddleware', 'withresponsemiddleware',
        'withtoken', 'withurlparameters', 'withuseragent',
        'withoutredirecting', 'withoutverifying',
    ];

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
            if ($this->declaresTimeout($cursor, $scope)) {
                return [];
            }

            // An unknown chain member — a Macroable extension (`->github()`)
            // or anything outside the known builder surface — may have set the
            // timeout internally. DECLINE, never a false positive.
            if (!$this->isKnownBuilder($cursor->name)) {
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

            if ($this->declaresTimeout($cursor, $scope)) {
                return [];
            }

            // Same macro guard at the static root — `Http::github()->get(...)`
            // may be a macro returning a pre-timed request.
            if (!$this->isKnownBuilder($cursor->name)) {
                return [];
            }

            return [$this->buildError($node)];
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
     * True when a builder call in the chain establishes (or MAY establish) a
     * request timeout: a `->timeout(...)` method, or a `->withOptions(...)`
     * whose options are not provably timeout-free.
     */
    private function declaresTimeout(MethodCall|StaticCall $call, Scope $scope): bool
    {
        $name = $this->methodName($call->name);

        if ($name === 'timeout') {
            return true;
        }

        if ($name === 'withoptions') {
            return $this->withOptionsMayCarryTimeout($call, $scope);
        }

        return false;
    }

    /**
     * Tri-state collapse over the `withOptions()` argument, by TYPE:
     *
     *   - constant array type carrying a `'timeout'` key (any union variant) —
     *     timed, chain is compliant;
     *   - constant array type(s) all provably WITHOUT the key — untimed, the
     *     chain stays a candidate;
     *   - anything else (a computed array, a helper/config() return, unknown) —
     *     POSSIBLY timed, so the chain declines: absence is unprovable and a
     *     false positive is the one unacceptable outcome (ADR-0021).
     *
     * The type path subsumes the old literal-`Array_` AST check (a literal
     * resolves to a constant array type) and additionally sees through a
     * variable holding a literal array.
     */
    private function withOptionsMayCarryTimeout(MethodCall|StaticCall $call, Scope $scope): bool
    {
        $first = $call->getArgs()[0]->value ?? null;

        if ($first === null) {
            // `withOptions()` with no argument adds nothing to the request.
            return false;
        }

        $constantArrays = $scope->getType($first)->getConstantArrays();

        if ($constantArrays === []) {
            return true;
        }

        foreach ($constantArrays as $constantArray) {
            foreach ($constantArray->getKeyTypes() as $keyType) {
                foreach ($keyType->getConstantStrings() as $keyString) {
                    if (mb_strtolower($keyString->getValue()) === 'timeout') {
                        return true;
                    }
                }
            }
        }

        return false;
    }

    private function isKnownBuilder(mixed $name): bool
    {
        return in_array($this->methodName($name), self::KNOWN_BUILDERS, true);
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
