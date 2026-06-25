<?php

declare(strict_types=1);

namespace Vortos\Alerts\Tests\Integration\Audit;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use Vortos\Alerts\Escalation\Acknowledgement;
use Vortos\Alerts\Event\AlertEvent;
use Vortos\Alerts\Event\AlertSource;
use Vortos\Alerts\Integration\Audit\AlertAuditRecorder;
use Vortos\Alerts\Integration\Audit\DbalAlertAuditViewRepository;
use Vortos\Alerts\Notifier\NotificationResult;
use Vortos\Alerts\Routing\RoutedDelivery;
use Vortos\Alerts\Severity;
use Vortos\Observability\Audit\AuditHashChain;

final class AlertAuditRecorderTest extends TestCase
{
    private Connection $connection;
    private AlertAuditRecorder $recorder;

    protected function setUp(): void
    {
        $this->connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $this->connection->executeStatement(
            'CREATE TABLE alerts_audit_log (
                entry_id VARCHAR(64) PRIMARY KEY,
                sequence INTEGER NOT NULL,
                env VARCHAR(64) NOT NULL,
                event_type VARCHAR(32) NOT NULL,
                fingerprint VARCHAR(64) NOT NULL,
                actor_id VARCHAR(255) NOT NULL,
                occurred_at VARCHAR(32) NOT NULL,
                data TEXT NOT NULL,
                prev_hash VARCHAR(64) NOT NULL,
                content_hash VARCHAR(64) NOT NULL,
                signature VARCHAR(64) NOT NULL
            )',
        );

        $repository = new DbalAlertAuditViewRepository($this->connection, 'alerts_audit_log');
        $this->recorder = new AlertAuditRecorder($repository, new AuditHashChain(), 'test-hmac-key');
    }

    public function test_page_and_ack_land_in_the_ledger_with_a_valid_chain(): void
    {
        $now = new DateTimeImmutable('2026-01-01T00:00:00+00:00');
        $event = AlertEvent::scrubbed(
            ruleId: 'r1',
            severity: Severity::Critical,
            title: 't',
            summary: 's',
            source: AlertSource::Health,
            env: 'prod',
            tenantId: null,
            labels: [],
            annotations: [],
            links: [],
            occurredAt: $now,
        );

        $notificationEntry = $this->recorder->recordNotification($event, new RoutedDelivery('oncall-page', 'telegram'), NotificationResult::delivered('telegram'), $now);
        $ackEntry = $this->recorder->recordAcknowledgement(new Acknowledgement('fp-1', 0, 'alice', $now), 'prod', $now->modify('+1 minute'));

        self::assertSame(0, $notificationEntry->sequence);
        self::assertSame(1, $ackEntry->sequence);
        self::assertSame($notificationEntry->contentHash, $ackEntry->prevHash, 'the chain must link sequential entries');

        $chain = new AuditHashChain();
        $signingMessage = $chain->signingMessage($ackEntry->entryId, $ackEntry->sequence, $ackEntry->contentHash, $ackEntry->prevHash);
        self::assertTrue($chain->verifySignature($signingMessage, $ackEntry->signature, 'test-hmac-key'));
        self::assertFalse($chain->verifySignature($signingMessage, $ackEntry->signature, 'wrong-key'));
    }

    public function test_genesis_entry_uses_genesis_hash(): void
    {
        $now = new DateTimeImmutable();
        $event = AlertEvent::scrubbed('r1', Severity::Warning, 't', 's', AlertSource::Health, 'staging', null, [], [], [], $now);

        $entry = $this->recorder->recordNotification($event, new RoutedDelivery('eng-chat', 'slack'), NotificationResult::delivered('slack'), $now);

        self::assertSame(AuditHashChain::GENESIS_HASH, $entry->prevHash);
    }
}
