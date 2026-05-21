<?php

declare(strict_types=1);

namespace App\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260430162132 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Vortos outbox — envelope-first schema';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE IF NOT EXISTS vortos_outbox (
    id                UUID          PRIMARY KEY,
    transport_name    VARCHAR(255)  NOT NULL,
    event_id          UUID          NOT NULL,
    aggregate_id      VARCHAR(255)  NOT NULL,
    aggregate_type    VARCHAR(512)  NOT NULL,
    aggregate_version INTEGER       NOT NULL,
    payload_type      VARCHAR(512)  NOT NULL,
    schema_version    INTEGER       NOT NULL DEFAULT 1,
    occurred_at       TIMESTAMP     NOT NULL,
    correlation_id    VARCHAR(255),
    causation_id      VARCHAR(255),
    trace_id          VARCHAR(255),
    metadata          JSONB,
    payload           TEXT          NOT NULL,
    status            VARCHAR(20)   NOT NULL DEFAULT \'pending\',
    attempt_count     INTEGER       NOT NULL DEFAULT 0,
    created_at        TIMESTAMP     NOT NULL DEFAULT NOW(),
    published_at      TIMESTAMP,
    next_attempt_at   TIMESTAMP,
    failure_reason    TEXT
)');
        $this->addSql('CREATE INDEX IF NOT EXISTS idx_vortos_outbox_status_next
    ON vortos_outbox (status, next_attempt_at)
    WHERE status = \'pending\'');
        $this->addSql('CREATE INDEX IF NOT EXISTS idx_vortos_outbox_aggregate_occurred
    ON vortos_outbox (aggregate_id, occurred_at)');
        $this->addSql('CREATE INDEX IF NOT EXISTS idx_vortos_outbox_type_occurred
    ON vortos_outbox (payload_type, occurred_at)');
    }

    public function down(Schema $schema): void
    {
        $this->throwIrreversibleMigrationException(
            'This migration was generated from a module SQL stub and has no automatic rollback.'
        );
    }
}
