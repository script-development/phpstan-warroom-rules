<?php

declare(strict_types = 1);

namespace ScriptDevelopment\PhpstanWarroomRules\Type;

use Illuminate\Database\ConnectionInterface;
use PhpParser\Node\Expr\MethodCall;
use PHPStan\Analyser\Scope;
use PHPStan\Reflection\MethodReflection;
use PHPStan\Type\DynamicMethodReturnTypeExtension;
use PHPStan\Type\Type;

/**
 * Resolves the return type of ConnectionInterface::transaction() to the closure's return type.
 *
 * Without this extension, $connection->transaction(fn () => $foo) returns mixed, which
 * breaks strict typing of transaction call sites and weakens downstream rules that
 * reason about transactional code.
 */
final class ConnectionTransactionReturnTypeExtension implements DynamicMethodReturnTypeExtension
{
    public function getClass(): string
    {
        return ConnectionInterface::class;
    }

    public function isMethodSupported(MethodReflection $methodReflection): bool
    {
        return $methodReflection->getName() === 'transaction';
    }

    public function getTypeFromMethodCall(
        MethodReflection $methodReflection,
        MethodCall $methodCall,
        Scope $scope,
    ): ?Type {
        $args = $methodCall->getArgs();

        if ($args === []) {
            return null;
        }

        $closureType = $scope->getType($args[0]->value);

        if (!$closureType->isCallable()->yes()) {
            return null;
        }

        $callableAcceptors = $closureType->getCallableParametersAcceptors($scope);

        if ($callableAcceptors === []) {
            return null;
        }

        return $callableAcceptors[0]->getReturnType();
    }
}
