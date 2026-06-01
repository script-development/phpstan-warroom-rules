<?php

declare(strict_types = 1);

namespace ScriptDevelopment\PhpstanWarroomRules\Tests\Rules;

use PHPStan\Rules\Rule;
use PHPStan\Testing\RuleTestCase;
use ScriptDevelopment\PhpstanWarroomRules\Rules\EnforceAuditTransactionScopeRule;

use function sprintf;

/**
 * @extends RuleTestCase<EnforceAuditTransactionScopeRule>
 */
final class EnforceAuditTransactionScopeRuleTest extends RuleTestCase
{
    public function testCompliantPostCommitMutation(): void
    {
        $this->analyse(
            [__DIR__ . '/../Fixtures/AuditTransactionScope/CompliantPostCommitMutation.php'],
            [],
        );
    }

    public function testCompliantSentinelReturn(): void
    {
        $this->analyse(
            [__DIR__ . '/../Fixtures/AuditTransactionScope/CompliantSentinelReturn.php'],
            [],
        );
    }

    public function testCompliantAuditOnlyAction(): void
    {
        $this->analyse(
            [__DIR__ . '/../Fixtures/AuditTransactionScope/CompliantAuditOnlyAction.php'],
            [],
        );
    }

    public function testViolationGuardLoginInsideClosure(): void
    {
        $this->analyse(
            [__DIR__ . '/../Fixtures/AuditTransactionScope/ViolationGuardLoginInsideClosure.php'],
            [
                [
                    $this->message(
                        'App\Actions\Auth\ViolationGuardLoginInsideClosure',
                        'StatefulGuard',
                        'login',
                    ),
                    23,
                ],
            ],
        );
    }

    public function testViolationSessionPutInsideClosure(): void
    {
        $this->analyse(
            [__DIR__ . '/../Fixtures/AuditTransactionScope/ViolationSessionPutInsideClosure.php'],
            [
                [
                    $this->message(
                        'App\Actions\Auth\ViolationSessionPutInsideClosure',
                        'Session',
                        'put',
                    ),
                    23,
                ],
            ],
        );
    }

    public function testViolationCacheFacadeInsideClosure(): void
    {
        $this->analyse(
            [__DIR__ . '/../Fixtures/AuditTransactionScope/ViolationCacheFacadeInsideClosure.php'],
            [
                [
                    $this->message(
                        'App\Actions\Widget\ViolationCacheFacadeInsideClosure',
                        'Cache',
                        'put',
                    ),
                    25,
                ],
            ],
        );
    }

    public function testViolationBusDispatchInsideClosure(): void
    {
        $this->analyse(
            [__DIR__ . '/../Fixtures/AuditTransactionScope/ViolationBusDispatchInsideClosure.php'],
            [
                [
                    $this->message(
                        'App\Actions\Widget\ViolationBusDispatchInsideClosure',
                        'Bus',
                        'dispatch',
                    ),
                    25,
                ],
            ],
        );
    }

    public function testViolationMailSendInsideClosure(): void
    {
        $this->analyse(
            [__DIR__ . '/../Fixtures/AuditTransactionScope/ViolationMailSendInsideClosure.php'],
            [
                [
                    $this->message(
                        'App\Actions\Widget\ViolationMailSendInsideClosure',
                        'Mailer',
                        'send',
                    ),
                    26,
                ],
            ],
        );
    }

    public function testViolationNotificationSendInsideClosure(): void
    {
        $this->analyse(
            [__DIR__ . '/../Fixtures/AuditTransactionScope/ViolationNotificationSendInsideClosure.php'],
            [
                [
                    $this->message(
                        'App\Actions\Widget\ViolationNotificationSendInsideClosure',
                        'Notification',
                        'send',
                    ),
                    26,
                ],
            ],
        );
    }

    public function testViolationStorageDeleteInsideClosure(): void
    {
        $this->analyse(
            [__DIR__ . '/../Fixtures/AuditTransactionScope/ViolationStorageDeleteInsideClosure.php'],
            [
                [
                    $this->message(
                        'App\Actions\Widget\ViolationStorageDeleteInsideClosure',
                        'Storage',
                        'delete',
                    ),
                    24,
                ],
            ],
        );
    }

    public function testReadMethodsAllowedInsideClosure(): void
    {
        $this->analyse(
            [__DIR__ . '/../Fixtures/AuditTransactionScope/ReadMethodsAllowedInsideClosure.php'],
            [],
        );
    }

    public function testNonActionNamespaceSkipped(): void
    {
        $this->analyse(
            [__DIR__ . '/../Fixtures/AuditTransactionScope/NonActionNamespaceSkipped.php'],
            [],
        );
    }

    public function testEmptyExecuteMethodSkipped(): void
    {
        $this->analyse(
            [__DIR__ . '/../Fixtures/AuditTransactionScope/EmptyExecuteMethodSkipped.php'],
            [],
        );
    }

    public function testNonClosureTransactionArgumentSkipped(): void
    {
        $this->analyse(
            [__DIR__ . '/../Fixtures/AuditTransactionScope/NonClosureTransactionArgumentSkipped.php'],
            [],
        );
    }

    public function testNestedTransactionMutationDetected(): void
    {
        $this->analyse(
            [__DIR__ . '/../Fixtures/AuditTransactionScope/NestedTransactionMutationDetected.php'],
            [
                [
                    $this->message(
                        'App\Actions\Widget\NestedTransactionMutationDetected',
                        'Cache',
                        'put',
                    ),
                    24,
                ],
            ],
        );
    }

    public function testArrowFunctionClosureSupported(): void
    {
        $this->analyse(
            [__DIR__ . '/../Fixtures/AuditTransactionScope/ArrowFunctionClosureSupported.php'],
            [
                [
                    $this->message(
                        'App\Actions\Widget\ArrowFunctionClosureSupported',
                        'Session',
                        'put',
                    ),
                    23,
                ],
            ],
        );
    }

    protected function getRule(): Rule
    {
        return new EnforceAuditTransactionScopeRule;
    }

    private function message(string $classFqcn, string $typeShortName, string $method): string
    {
        return sprintf(
            'Action %s mutates non-transactional state (%s::%s) inside a database transaction closure. '
            . 'ADR-0029 (Audit Row Durability Contract) requires non-transactional state mutation to happen '
            . 'post-commit outside the closure, so an audit-write failure cannot leave observable side effects '
            . 'without an audit row.',
            $classFqcn,
            $typeShortName,
            $method,
        );
    }
}
