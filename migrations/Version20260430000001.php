<?php

declare(strict_types=1);

namespace App\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260430000001 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Vortos outbox — transactional event publishing staging table';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE IF NOT EXISTS vortos_outbox (
                id              UUID         PRIMARY KEY,
                transport_name  VARCHAR(255) NOT NULL,
                event_class     VARCHAR(512) NOT NULL,
                payload         TEXT         NOT NULL,
                headers         JSONB        NOT NULL DEFAULT '{}',
                status          VARCHAR(20)  NOT NULL DEFAULT 'pending',
                attempt_count   INTEGER      NOT NULL DEFAULT 0,
                created_at      TIMESTAMP    NOT NULL DEFAULT NOW(),
                published_at    TIMESTAMP,
                next_attempt_at TIMESTAMP,
                failure_reason  TEXT
            )
        SQL);

        $this->addSql(<<<'SQL'
            CREATE INDEX IF NOT EXISTS idx_vortos_outbox_status_created
                ON vortos_outbox (status, created_at)
                WHERE status = 'pending'
        SQL);
    }

    public function down(Schema $schema): void
    {
        $this->throwIrreversibleMigrationException(
            'Dropping the outbox table destroys undelivered events. Perform manually if intentional.',
        );
    }
}
