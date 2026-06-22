<?php

declare(strict_types=1);

namespace Vortos\FeatureFlags\Tests\ReadModel;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use Vortos\Domain\Event\EventEnvelope;
use Vortos\Domain\Event\Metadata;
use Vortos\FeatureFlags\Domain\Event\FlagCreatedEvent;
use Vortos\FeatureFlags\Domain\Event\FlagEnabledEvent;
use Vortos\FeatureFlags\Projection\FlagReadModelProjector;
use Vortos\FeatureFlags\ReadModel\DbalFlagAuditLogRepository;
use Vortos\FeatureFlags\ReadModel\DbalFlagStateViewRepository;
use Vortos\FeatureFlags\ReadModel\FlagAuditEntry;
use Vortos\FeatureFlags\ReadModel\FlagStateView;

final class DbalReadModelTest extends TestCase
{
    private const FLAG_ID = '11111111-1111-4111-8111-111111111111';

    private Connection $connection;
    private DbalFlagAuditLogRepository $audit;
    private DbalFlagStateViewRepository $state;

    protected function setUp(): void
    {
        $this->connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $this->connection->executeStatement(
            'CREATE TABLE ff_audit (event_id VARCHAR(36) NOT NULL, flag_id VARCHAR(36) NOT NULL, flag_name VARCHAR(255) NOT NULL, environment VARCHAR(64) NOT NULL DEFAULT \'production\', event_type VARCHAR(64) NOT NULL, actor_id VARCHAR(191) NOT NULL, reason TEXT, occurred_at VARCHAR(40) NOT NULL, data TEXT NOT NULL DEFAULT \'{}\', PRIMARY KEY (event_id))',
        );
        $this->connection->executeStatement(
            'CREATE TABLE ff_state (environment VARCHAR(64) NOT NULL DEFAULT \'production\', flag_name VARCHAR(255) NOT NULL, flag_id VARCHAR(36) NOT NULL, enabled SMALLINT NOT NULL DEFAULT 0, archived SMALLINT NOT NULL DEFAULT 0, value_type VARCHAR(16) NOT NULL DEFAULT \'bool\', kind VARCHAR(16) NOT NULL DEFAULT \'release\', rule_count INTEGER NOT NULL DEFAULT 0, variants TEXT, scheduled SMALLINT NOT NULL DEFAULT 0, last_event_type VARCHAR(64) NOT NULL DEFAULT \'\', last_actor_id VARCHAR(191) NOT NULL DEFAULT \'\', updated_at VARCHAR(40) NOT NULL DEFAULT \'\', PRIMARY KEY (environment, flag_name))',
        );
        $this->audit = new DbalFlagAuditLogRepository($this->connection, 'ff_audit');
        $this->state = new DbalFlagStateViewRepository($this->connection, 'ff_state');
    }

    public function test_audit_upsert_and_find_by_flag(): void
    {
        $this->audit->upsert($this->entry('evt-1', 'dark-mode', 'FlagCreatedEvent'));
        $this->audit->upsert($this->entry('evt-2', 'dark-mode', 'FlagEnabledEvent'));

        $rows = $this->audit->findByFlag('dark-mode');

        $this->assertCount(2, $rows);
        $this->assertContainsOnlyInstancesOf(FlagAuditEntry::class, $rows);
    }

    public function test_audit_upsert_is_idempotent_by_event_id(): void
    {
        $this->audit->upsert($this->entry('evt-1', 'f', 'FlagCreatedEvent'));
        $this->audit->upsert($this->entry('evt-1', 'f', 'FlagCreatedEvent')); // re-delivery

        $this->assertCount(1, $this->audit->findByFlag('f'));
    }

    public function test_state_view_upsert_find_and_all(): void
    {
        $this->state->upsert($this->view('a', enabled: true));
        $this->state->upsert($this->view('b', enabled: false));
        $this->state->upsert($this->view('a', enabled: false)); // update, not insert

        $a = $this->state->findByName('a');
        $this->assertNotNull($a);
        $this->assertFalse($a->enabled);
        $this->assertCount(2, $this->state->all());
    }

    public function test_projector_writes_through_to_dbal(): void
    {
        $projector = new FlagReadModelProjector($this->audit, $this->state);

        $projector->apply($this->envelope(new FlagCreatedEvent(
            self::FLAG_ID, 'checkout',
            ['name' => 'checkout', 'enabled' => false, 'value_type' => 'bool', 'kind' => 'release', 'rules' => []],
            'admin-1',
        ), 'e1'));
        $projector->apply($this->envelope(new FlagEnabledEvent(self::FLAG_ID, 'checkout', 'admin-1'), 'e2'));

        $view = $this->state->findByName('checkout');
        $this->assertNotNull($view);
        $this->assertTrue($view->enabled);
        $this->assertCount(2, $this->audit->findByFlag('checkout'));
    }

    private function entry(string $eventId, string $flag, string $type): FlagAuditEntry
    {
        return new FlagAuditEntry($eventId, self::FLAG_ID, $flag, $type, 'admin-1', null, '2026-06-21T10:00:00+00:00', ['k' => 'v']);
    }

    private function view(string $name, bool $enabled): FlagStateView
    {
        return new FlagStateView($name, self::FLAG_ID, $enabled, false, 'bool', 'release', 0, null, false, 'FlagCreatedEvent', 'admin-1', '2026-06-21T10:00:00+00:00');
    }

    private function envelope(object $payload, string $eventId): EventEnvelope
    {
        return new EventEnvelope(
            eventId: $eventId,
            aggregateId: self::FLAG_ID,
            aggregateType: 'Flag',
            aggregateVersion: 1,
            payloadType: $payload::class,
            schemaVersion: 1,
            occurredAt: new \DateTimeImmutable('2026-06-21T10:00:00Z'),
            payload: $payload,
            metadata: Metadata::empty(),
        );
    }
}
