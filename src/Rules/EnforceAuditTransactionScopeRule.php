<?php

declare(strict_types = 1);

namespace ScriptDevelopment\PhpstanWarroomRules\Rules;

use Illuminate\Contracts\Auth\StatefulGuard;
use Illuminate\Contracts\Broadcasting\Broadcaster;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Contracts\Mail\Mailer;
use Illuminate\Contracts\Queue\Queue;
use Illuminate\Contracts\Session\Session;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use PhpParser\Node;
use PhpParser\Node\Arg;
use PhpParser\Node\Expr\ArrowFunction;
use PhpParser\Node\Expr\Closure;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\PropertyFetch;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\Stmt\Class_;
use PhpParser\Node\Stmt\ClassMethod;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\IdentifierRuleError;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;

use function in_array;
use function is_array;
use function is_string;
use function mb_strrpos;
use function mb_substr;
use function sprintf;
use function str_starts_with;

/**
 * Enforces ADR-0029 (Audit Row Durability Contract) §Decision rule 3: non-
 * transactional state mutations MUST NOT happen inside a `transaction(...)`
 * closure in an `App\Actions\*` class. Mutating session, guard, cache, queue,
 * mail, notification, broadcast, or filesystem state inside the closure leaks
 * observable side effects whenever the audit-row write rolls back — the
 * forensic guarantee the audit row was meant to provide is broken.
 *
 * Doctrine source: ADR-0029 (Audit Row Durability Contract) §Decision rule 3.
 *
 * The companion failure-side discipline (sentinel-return; throws inside the
 * closure roll back the failure-audit row) lives in per-territory Pest arch
 * tests under enforcement queue #85 — out of scope for this rule.
 *
 * Algorithm:
 *
 *   1. Namespace gate — class must live under `App\Actions\*`.
 *   2. Inspect `execute()`. Skip if absent/empty.
 *   3. Discover every `MethodCall|StaticCall` named `transaction` in the
 *      method body (including nested).
 *   4. For each `transaction(...)` call, inspect its first argument. If it
 *      is a `Closure` or `ArrowFunction`, recursively walk the closure body
 *      looking for blocklist violations. Nested `transaction(...)` calls
 *      inside the outer closure are walked into transitively — a nested
 *      mutation is still inside the outer transaction's rollback scope.
 *
 * Blocklist — mutation methods only on a canonical set of facades + contracts.
 * Reads (`Auth::user()`, `Session::get()`, `Cache::get()`, etc.) are
 * deliberately permitted; only writes carry the rollback-vs-side-effect
 * asymmetry the rule guards against.
 *
 * Instance-call detection — `$this->guard->login(...)` form: walks the
 * `PropertyFetch` to a constructor parameter and matches the declared type
 * (or its short FQCN) against the blocklist keys. Same shape as
 * `EnforceActionTransactionsRule::getNonDatabasePropertyNames()`.
 *
 * Static-facade detection — `Auth::login(...)` form: matches the `StaticCall`
 * class via `$scope->resolveName()` against the blocklist's facade FQCNs.
 *
 * Out of scope:
 *
 *   - Manual transaction management (`DB::beginTransaction()` / `commit()`).
 *     Only `transaction(Closure)` with a literal closure first-arg fires.
 *   - Non-`App\Actions\*` namespaces — Services, Jobs, Commands, Middleware
 *     have their own disciplines (or lack thereof) that ADR-0029 doesn't
 *     cover.
 *   - Reads of facades/contracts inside the closure — explicitly permitted.
 *
 * @implements Rule<Class_>
 */
final class EnforceAuditTransactionScopeRule implements Rule
{
    /**
     * Blocklist of mutation methods that MUST NOT execute inside a
     * `transaction(...)` closure when the receiver is the named type.
     *
     * Keys are fully-qualified class names (facades + contracts). Values are
     * the mutation methods. Reads are deliberately omitted — `Auth::user()`,
     * `Session::get()`, `Cache::get()`, etc. carry no rollback risk.
     *
     * @var array<string, list<string>>
     */
    private const array BLOCKLIST = [
        // StatefulGuard — worker auth, web guard. Mutation methods only.
        StatefulGuard::class => [
            'login', 'logout', 'attempt', 'loginUsingId', 'logoutCurrentDevice',
            'viaRemember', 'once', 'onceUsingId', 'attemptWhen', 'logoutOtherDevices',
        ],
        // Auth facade — static-call form of StatefulGuard mutations.
        Auth::class => [
            'login', 'logout', 'attempt', 'loginUsingId', 'logoutCurrentDevice',
            'viaRemember', 'once', 'onceUsingId', 'attemptWhen', 'logoutOtherDevices',
        ],
        // Session contract — non-transactional session-store mutations.
        Session::class => [
            'regenerate', 'invalidate', 'put', 'forget', 'remove', 'flush', 'migrate',
            'regenerateToken', 'flash', 'reflash', 'keep', 'now', 'increment', 'decrement',
            'push', 'replace', 'pull',
        ],
        \Illuminate\Support\Facades\Session::class => [
            'regenerate', 'invalidate', 'put', 'forget', 'remove', 'flush', 'migrate',
            'regenerateToken', 'flash', 'reflash', 'keep', 'now', 'increment', 'decrement',
            'push', 'replace', 'pull',
        ],
        // Cache repository — mutation methods only.
        Repository::class => [
            'put', 'forget', 'flush', 'delete', 'add', 'pull', 'increment', 'decrement',
            'forever', 'remember', 'rememberForever', 'sear', 'restoreLock',
        ],
        Cache::class => [
            'put', 'forget', 'flush', 'delete', 'add', 'pull', 'increment', 'decrement',
            'forever', 'remember', 'rememberForever', 'sear',
        ],
        // Bus / Queue dispatching.
        Dispatcher::class => [
            'dispatch', 'dispatchNow', 'dispatchSync', 'dispatchAfterResponse', 'dispatchToQueue',
        ],
        Bus::class => [
            'dispatch', 'dispatchNow', 'dispatchSync', 'dispatchAfterResponse',
        ],
        Queue::class => [
            'push', 'pushOn', 'later', 'laterOn', 'bulk',
        ],
        \Illuminate\Support\Facades\Queue::class => [
            'push', 'pushOn', 'later', 'laterOn', 'bulk',
        ],
        // Mail.
        Mailer::class => [
            'send', 'sendNow', 'raw', 'plain', 'html', 'queue', 'later',
        ],
        Mail::class => [
            'send', 'raw', 'plain', 'html', 'queue', 'later', 'to', 'cc', 'bcc',
        ],
        // Notifications.
        \Illuminate\Contracts\Notifications\Dispatcher::class => [
            'send', 'sendNow',
        ],
        Notification::class => [
            'send', 'sendNow', 'route',
        ],
        // Broadcasting.
        Broadcaster::class => [
            'broadcast',
        ],
        Broadcast::class => [
            'event', 'channel',
        ],
        // Filesystem mutations.
        Filesystem::class => [
            'put', 'putFile', 'putFileAs', 'delete', 'move', 'copy', 'prepend', 'append',
            'setVisibility', 'makeDirectory', 'deleteDirectory',
        ],
        Storage::class => [
            'put', 'putFile', 'putFileAs', 'delete', 'move', 'copy', 'prepend', 'append',
            'setVisibility', 'makeDirectory', 'deleteDirectory',
        ],
    ];

    public function getNodeType(): string
    {
        return Class_::class;
    }

    public function processNode(Node $node, Scope $scope): array
    {
        $namespace = $scope->getNamespace();

        if ($namespace === null || !str_starts_with($namespace, 'App\Actions')) {
            return [];
        }

        $executeMethod = $node->getMethod('execute');

        if ($executeMethod === null || $executeMethod->stmts === null) {
            return [];
        }

        $classFqcn = $this->resolveClassFqcn($node, $namespace);
        $constructorPropertyTypes = $this->getConstructorPropertyTypes($node);
        $transactionCalls = $this->findTransactionCalls($executeMethod);

        $errors = [];

        foreach ($transactionCalls as $transactionCall) {
            $closure = $this->extractClosureArgument($transactionCall);

            if ($closure === null) {
                continue;
            }

            foreach ($this->findBlocklistViolations($closure, $constructorPropertyTypes, $scope) as $violation) {
                $errors[] = $this->buildError($classFqcn, $violation);
            }
        }

        return $errors;
    }

    /**
     * @param array{type: string, method: string, node: Node} $violation
     */
    private function buildError(string $classFqcn, array $violation): IdentifierRuleError
    {
        $typeShortName = $this->shortName($violation['type']);

        $message = sprintf(
            'Action %s mutates non-transactional state (%s::%s) inside a database transaction closure. '
            . 'ADR-0029 (Audit Row Durability Contract) requires non-transactional state mutation to happen '
            . 'post-commit outside the closure, so an audit-write failure cannot leave observable side effects '
            . 'without an audit row.',
            $classFqcn,
            $typeShortName,
            $violation['method'],
        );

        return RuleErrorBuilder::message($message)
            ->identifier('enforceAuditTransactionScope.nonTransactionalMutationInClosure')
            ->line($violation['node']->getStartLine())
            ->build();
    }

    /**
     * Walk the method body and return every top-level `transaction()` call
     * node — i.e. transaction calls that are NOT themselves nested inside
     * another transaction closure. Nested transactions are processed by
     * `findBlocklistViolations()` recursing into the outer closure's body;
     * counting them here too would double-report every nested-call
     * violation.
     *
     * Covers `MethodCall` (`$this->db->transaction(...)`) and `StaticCall`
     * (`DB::transaction(...)`) shapes.
     *
     * @return list<MethodCall|StaticCall>
     */
    private function findTransactionCalls(ClassMethod $method): array
    {
        $calls = [];

        foreach ($method->stmts ?? [] as $stmt) {
            $this->collectTopLevelTransactions($stmt, $calls);
        }

        return $calls;
    }

    /**
     * @param list<MethodCall|StaticCall> $calls
     */
    private function collectTopLevelTransactions(Node $node, array &$calls): void
    {
        if ($this->isTransactionCall($node)) {
            /** @var MethodCall|StaticCall $node */
            $calls[] = $node;

            // Don't recurse into a transaction call's children — its closure
            // body is processed by findBlocklistViolations, and nested
            // transactions inside the closure are walked there.
            return;
        }

        foreach ($node->getSubNodeNames() as $name) {
            $subNode = $node->{$name};

            if ($subNode instanceof Node) {
                $this->collectTopLevelTransactions($subNode, $calls);
            } elseif (is_array($subNode)) {
                foreach ($subNode as $item) {
                    if ($item instanceof Node) {
                        $this->collectTopLevelTransactions($item, $calls);
                    }
                }
            }
        }
    }

    private function isTransactionCall(Node $node): bool
    {
        if (
            $node instanceof MethodCall
            && $node->name instanceof Identifier
            && $node->name->toString() === 'transaction'
        ) {
            return true;
        }

        return $node instanceof StaticCall
            && $node->name instanceof Identifier
            && $node->name->toString() === 'transaction';
    }

    /**
     * Extract the first-argument closure (or arrow function) from a
     * `transaction()` call. Returns null if the first argument is not a
     * literal closure — uncommon shapes (passing a Closure variable) are
     * out of scope.
     */
    private function extractClosureArgument(MethodCall|StaticCall $call): ArrowFunction|Closure|null
    {
        if (!isset($call->args[0]) || !$call->args[0] instanceof Arg) {
            return null;
        }

        $value = $call->args[0]->value;

        if ($value instanceof Closure || $value instanceof ArrowFunction) {
            return $value;
        }

        return null;
    }

    /**
     * Walk the closure body recursively looking for blocklist violations.
     * Nested closures + nested `transaction(...)` calls are walked into —
     * a nested mutation still sits inside the outer transaction's rollback
     * scope.
     *
     * @param array<string, string> $constructorPropertyTypes property-name => FQCN
     *
     * @return list<array{type: string, method: string, node: MethodCall|StaticCall}>
     */
    private function findBlocklistViolations(
        ArrowFunction|Closure $closure,
        array $constructorPropertyTypes,
        Scope $scope,
    ): array {
        $violations = [];

        $body = $closure instanceof Closure ? $closure->stmts : [$closure->expr];

        $this->walkNodes($body, function(Node $node) use (&$violations, $constructorPropertyTypes, $scope): void {
            if ($node instanceof MethodCall) {
                $violation = $this->checkInstanceCall($node, $constructorPropertyTypes);

                if ($violation !== null) {
                    $violations[] = $violation;
                }

                return;
            }

            if ($node instanceof StaticCall) {
                $violation = $this->checkStaticCall($node, $scope);

                if ($violation !== null) {
                    $violations[] = $violation;
                }
            }
        });

        return $violations;
    }

    /**
     * Match `$this->property->method(...)` against the blocklist by resolving
     * the property's declared constructor type.
     *
     * @param array<string, string> $constructorPropertyTypes property-name => FQCN
     *
     * @return array{type: string, method: string, node: MethodCall}|null
     */
    private function checkInstanceCall(MethodCall $node, array $constructorPropertyTypes): ?array
    {
        if (!$node->name instanceof Identifier) {
            return null;
        }

        $methodName = $node->name->toString();

        if (
            !$node->var instanceof PropertyFetch
            || !$node->var->var instanceof Variable
            || $node->var->var->name !== 'this'
            || !$node->var->name instanceof Identifier
        ) {
            return null;
        }

        $propertyName = $node->var->name->toString();
        $propertyType = $constructorPropertyTypes[$propertyName] ?? null;

        if ($propertyType === null) {
            return null;
        }

        if (!isset(self::BLOCKLIST[$propertyType])) {
            return null;
        }

        if (!in_array($methodName, self::BLOCKLIST[$propertyType], true)) {
            return null;
        }

        return ['type' => $propertyType, 'method' => $methodName, 'node' => $node];
    }

    /**
     * Match `Auth::method(...)` against the blocklist by resolving the
     * static-call's class name through the file's use-import map.
     *
     * @return array{type: string, method: string, node: StaticCall}|null
     */
    private function checkStaticCall(StaticCall $node, Scope $scope): ?array
    {
        if (!$node->name instanceof Identifier) {
            return null;
        }

        $methodName = $node->name->toString();

        if (!$node->class instanceof Name) {
            return null;
        }

        $resolvedClass = $scope->resolveName($node->class);

        if (!isset(self::BLOCKLIST[$resolvedClass])) {
            return null;
        }

        if (!in_array($methodName, self::BLOCKLIST[$resolvedClass], true)) {
            return null;
        }

        return ['type' => $resolvedClass, 'method' => $methodName, 'node' => $node];
    }

    /**
     * Build a property-name => FQCN map from the constructor's promoted
     * parameters. Mirrors `EnforceActionTransactionsRule::getNonDatabase
     * PropertyNames()` but returns the FQCN as the value so callers can
     * match against blocklist keys directly.
     *
     * @return array<string, string>
     */
    private function getConstructorPropertyTypes(Class_ $node): array
    {
        $constructor = $node->getMethod('__construct');

        if (!$constructor instanceof ClassMethod) {
            return [];
        }

        $map = [];

        foreach ($constructor->getParams() as $param) {
            if (!$param->type instanceof Name) {
                continue;
            }

            if (!$param->var instanceof Variable) {
                continue;
            }

            if (!is_string($param->var->name)) {
                continue;
            }

            $map[$param->var->name] = $param->type->toString();
        }

        return $map;
    }

    /**
     * Resolve the fully-qualified class name from the AST node + namespace.
     * Avoids depending on `$scope->getClassReflection()`, which can return
     * null during fixture-mode analysis where the class isn't autoloadable.
     */
    private function resolveClassFqcn(Class_ $node, string $namespace): string
    {
        if ($node->name === null) {
            return $namespace;
        }

        return $namespace . '\\' . $node->name->toString();
    }

    private function shortName(string $fqcn): string
    {
        $pos = mb_strrpos($fqcn, '\\');

        if ($pos === false) {
            return $fqcn;
        }

        return mb_substr($fqcn, $pos + 1);
    }

    /**
     * Recursively walk a list of nodes, invoking `$callback` on each one.
     * Mirrors `EnforceActionTransactionsRule::walkNodes()` for parity —
     * re-evaluate at v1.0 once the duplication trigger is acted on.
     *
     * @param array<int|string, Node|null> $nodes
     */
    private function walkNodes(array $nodes, callable $callback): void
    {
        foreach ($nodes as $node) {
            if (!$node instanceof Node) {
                continue;
            }

            $callback($node);

            foreach ($node->getSubNodeNames() as $name) {
                $subNode = $node->{$name};

                if ($subNode instanceof Node) {
                    $this->walkNodes([$subNode], $callback);
                } elseif (is_array($subNode)) {
                    $this->walkNodes(
                        array_filter($subNode, static fn(mixed $item): bool => $item instanceof Node),
                        $callback,
                    );
                }
            }
        }
    }
}
