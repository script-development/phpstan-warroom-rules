<?php

declare(strict_types = 1);

namespace ScriptDevelopment\PhpstanWarroomRules\Rules;

use Illuminate\Cache\Repository;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Filesystem\FilesystemManager;
use Illuminate\Log\LogManager;
use Illuminate\Mail\Mailer;
use PhpParser\Node;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\PropertyFetch;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\Stmt\Class_;
use PhpParser\Node\Stmt\ClassMethod;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;
use Psr\Log\LoggerInterface;

use function in_array;
use function is_array;
use function is_string;
use function str_starts_with;

/**
 * Enforces that Action classes with multiple write operations wrap them in a database transaction.
 *
 * Detects method calls like save(), create(), delete(), sync(), etc. in execute().
 * If 2+ write operations are found and no ->transaction() call is present, an error is reported.
 * Calls on non-database properties (e.g. FilesystemManager::delete()) are excluded via constructor type analysis.
 *
 * Doctrine source: ADR-0011 (Action Class Architecture).
 *
 * @implements Rule<Class_>
 */
final class EnforceActionTransactionsRule implements Rule
{
    private const array WRITE_METHODS = [
        'save',
        'saveQuietly',
        'create',
        'update',
        'delete',
        'forceDelete',
        'sync',
        'attach',
        'detach',
        'insert',
        'upsert',
        'updateOrCreate',
        'firstOrCreate',
        'push',
        'restore',
        'toggle',
        'syncWithoutDetaching',
        'syncWithPivotValues',
    ];

    /**
     * Constructor parameter types that are NOT database-related.
     * Write-like method calls (e.g. delete()) on these types are excluded from the count.
     */
    private const array NON_DATABASE_TYPES = [
        FilesystemManager::class,
        Filesystem::class,
        Repository::class,
        \Illuminate\Contracts\Cache\Repository::class,
        LogManager::class,
        LoggerInterface::class,
        Mailer::class,
        \Illuminate\Contracts\Mail\Mailer::class,
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

        $nonDatabaseProperties = $this->getNonDatabasePropertyNames($node);
        $writeCount = $this->countWriteCalls($executeMethod, $nonDatabaseProperties);
        $hasTransaction = $this->hasTransactionCall($executeMethod);

        if ($writeCount >= 2 && !$hasTransaction) {
            return [
                RuleErrorBuilder::message(
                    "Action has {$writeCount} write operations without a database transaction. "
                    . 'Inject ConnectionInterface and wrap writes in $this->connection->transaction().',
                )
                    ->identifier('enforceActionTransactions.missingTransaction')
                    ->line($executeMethod->getStartLine())
                    ->build(),
            ];
        }

        return [];
    }

    /**
     * @param array<int, string> $nonDatabaseProperties
     */
    private function countWriteCalls(ClassMethod $method, array $nonDatabaseProperties): int
    {
        $count = 0;
        $this->walkNodes($method->stmts ?? [], function(Node $node) use (&$count, $nonDatabaseProperties): void {
            if (
                $node instanceof MethodCall
                && $node->name instanceof Identifier
                && in_array($node->name->toString(), self::WRITE_METHODS, true)
                && !$this->isOnNonDatabaseProperty($node, $nonDatabaseProperties)
            ) {
                $count++;
            }
        });

        return $count;
    }

    /**
     * @param array<int, string> $nonDatabaseProperties
     */
    private function isOnNonDatabaseProperty(MethodCall $node, array $nonDatabaseProperties): bool
    {
        if (
            !$node->var instanceof PropertyFetch
            || !$node->var->var instanceof Variable
            || $node->var->var->name !== 'this'
            || !$node->var->name instanceof Identifier
        ) {
            return false;
        }

        return in_array($node->var->name->toString(), $nonDatabaseProperties, true);
    }

    /**
     * @return array<int, string>
     */
    private function getNonDatabasePropertyNames(Class_ $node): array
    {
        $constructor = $node->getMethod('__construct');

        if (!$constructor instanceof ClassMethod) {
            return [];
        }

        $nonDbProperties = [];

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

            $typeName = $param->type->toString();

            if (in_array($typeName, self::NON_DATABASE_TYPES, true)) {
                $nonDbProperties[] = $param->var->name;
            }
        }

        return $nonDbProperties;
    }

    private function hasTransactionCall(ClassMethod $method): bool
    {
        $found = false;
        $this->walkNodes($method->stmts ?? [], function(Node $node) use (&$found): void {
            if (
                $node instanceof MethodCall
                && $node->name instanceof Identifier
                && $node->name->toString() === 'transaction'
            ) {
                $found = true;
            }
        });

        return $found;
    }

    /**
     * @param array<int|string, Node> $nodes
     */
    private function walkNodes(array $nodes, callable $callback): void
    {
        foreach ($nodes as $node) {
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
