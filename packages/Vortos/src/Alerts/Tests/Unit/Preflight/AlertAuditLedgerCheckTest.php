<?php

declare(strict_types=1);

namespace Vortos\Alerts\Tests\Unit\Preflight;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Vortos\Alerts\Event\AlertEvent;
use Vortos\Alerts\Integration\Audit\AlertAuditEntry;
use Vortos\Alerts\Integration\Audit\AlertAuditRecorderInterface;
use Vortos\Alerts\Notifier\NotificationResult;
use Vortos\Alerts\Preflight\AlertAuditLedgerCheck;
use Vortos\Alerts\Routing\RoutedDelivery;
use Vortos\Deploy\Preflight\PreflightStatus;
use Vortos\Deploy\Tests\Fixtures\PreflightTestFactory;

final class AlertAuditLedgerCheckTest extends TestCase
{
    use PreflightTestFactory;

    /**
     * The production state this check was written for: alerts delivering to Slack normally while
     * the tamper-evident ledger held zero rows, with no error anywhere. Nothing in the alert path
     * can notice, because the dispatcher swallows recording failures on purpose.
     */
    public function test_fails_when_the_ledger_cannot_sign(): void
    {
        $finding = (new AlertAuditLedgerCheck(new FakeAuditRecorder(operational: false)))->check($this->context());

        self::assertSame(PreflightStatus::Fail, $finding->status);
        self::assertStringContainsString('ALERTS_AUDIT_HMAC_KEY', $finding->detail);
    }

    public function test_fails_when_no_recorder_is_registered(): void
    {
        $finding = (new AlertAuditLedgerCheck(null))->check($this->context());

        self::assertSame(PreflightStatus::Fail, $finding->status);
    }

    public function test_passes_when_the_ledger_is_recording(): void
    {
        $finding = (new AlertAuditLedgerCheck(new FakeAuditRecorder(operational: true)))->check($this->context());

        self::assertSame(PreflightStatus::Pass, $finding->status);
    }
}

final class FakeAuditRecorder implements AlertAuditRecorderInterface
{
    public function __construct(private readonly bool $operational) {}

    public function isOperational(): bool
    {
        return $this->operational;
    }

    public function recordNotification(
        AlertEvent $event,
        RoutedDelivery $delivery,
        NotificationResult $result,
        DateTimeImmutable $now,
    ): AlertAuditEntry {
        throw new \LogicException('not used');
    }
}
