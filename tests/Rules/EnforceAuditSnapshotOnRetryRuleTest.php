<?php

declare(strict_types = 1);

namespace ScriptDevelopment\PhpstanWarroomRules\Tests\Rules;

use PHPStan\Rules\Rule;
use PHPStan\Testing\RuleTestCase;
use ScriptDevelopment\PhpstanWarroomRules\Rules\EnforceAuditSnapshotOnRetryRule;

/**
 * @extends RuleTestCase<EnforceAuditSnapshotOnRetryRule>
 */
final class EnforceAuditSnapshotOnRetryRuleTest extends RuleTestCase
{
    private const string EXPECTED_MESSAGE = 'First statement of audit-writing transaction closure must be one of: '
        . '$model->refresh() (update), $model = $this->prop->newQuery()->find*/first*/->fresh() (delete), '
        . 'or $model = new SomeClass(...) / $this->prop->newInstance() (create). '
        . 'Without state reset, an $attempts >= 2 retry replays mutated in-memory state. '
        . 'Precede the transaction call with `// @audit-snapshot-retry-safety: <rationale>` to opt out. '
        . 'See ADR-0001 §Snapshot-on-Retry Safety.';

    public function testUpdateActionWithRefreshIsCompliant(): void
    {
        $this->analyse(
            [__DIR__ . '/../Fixtures/AuditSnapshotOnRetry/UpdateActionWithRefresh.php'],
            [],
        );
    }

    public function testUpdateActionWithPropertyRefreshIsCompliant(): void
    {
        $this->analyse(
            [__DIR__ . '/../Fixtures/AuditSnapshotOnRetry/UpdateActionPropertyRefresh.php'],
            [],
        );
    }

    public function testDeleteActionWithFreshFetchIsCompliant(): void
    {
        $this->analyse(
            [__DIR__ . '/../Fixtures/AuditSnapshotOnRetry/DeleteActionWithFreshFetch.php'],
            [],
        );
    }

    public function testDeleteActionWithFreshIsCompliant(): void
    {
        $this->analyse(
            [__DIR__ . '/../Fixtures/AuditSnapshotOnRetry/DeleteActionWithFresh.php'],
            [],
        );
    }

    public function testCreateActionWithNewIsCompliant(): void
    {
        $this->analyse(
            [__DIR__ . '/../Fixtures/AuditSnapshotOnRetry/CreateActionWithNew.php'],
            [],
        );
    }

    public function testCreateActionWithNewInstanceIsCompliant(): void
    {
        $this->analyse(
            [__DIR__ . '/../Fixtures/AuditSnapshotOnRetry/CreateActionWithNewInstance.php'],
            [],
        );
    }

    public function testUpdateActionMissingRefreshIsFlagged(): void
    {
        $this->analyse(
            [__DIR__ . '/../Fixtures/AuditSnapshotOnRetry/UpdateActionMissingRefresh.php'],
            [
                [self::EXPECTED_MESSAGE, 20],
            ],
        );
    }

    public function testDeleteActionMissingFreshFetchIsFlagged(): void
    {
        $this->analyse(
            [__DIR__ . '/../Fixtures/AuditSnapshotOnRetry/DeleteActionMissingFreshFetch.php'],
            [
                [self::EXPECTED_MESSAGE, 20],
            ],
        );
    }

    public function testUpdateActionWithMarkerExemptionIsAllowed(): void
    {
        $this->analyse(
            [__DIR__ . '/../Fixtures/AuditSnapshotOnRetry/UpdateActionWithMarkerExemption.php'],
            [],
        );
    }

    public function testUpdateActionInIfBlockIsCompliant(): void
    {
        $this->analyse(
            [__DIR__ . '/../Fixtures/AuditSnapshotOnRetry/UpdateActionInIfBlock.php'],
            [],
        );
    }

    public function testActionWithoutAuditLoggerIsOutOfScope(): void
    {
        $this->analyse(
            [__DIR__ . '/../Fixtures/AuditSnapshotOnRetry/ActionWithoutAuditLogger.php'],
            [],
        );
    }

    public function testActionWithChannelLoggerOnlyIsOutOfScope(): void
    {
        $this->analyse(
            [__DIR__ . '/../Fixtures/AuditSnapshotOnRetry/ActionWithChannelLoggerOnly.php'],
            [],
        );
    }

    public function testNonActionClassWithTransactionIsOutOfScope(): void
    {
        $this->analyse(
            [__DIR__ . '/../Fixtures/AuditSnapshotOnRetry/NonActionClassWithTransaction.php'],
            [],
        );
    }

    public function testActionWithUnrelatedTransactionReceiverIsSkipped(): void
    {
        $this->analyse(
            [__DIR__ . '/../Fixtures/AuditSnapshotOnRetry/UpdateActionWithUnrelatedTransaction.php'],
            [],
        );
    }

    protected function getRule(): Rule
    {
        return new EnforceAuditSnapshotOnRetryRule;
    }
}
