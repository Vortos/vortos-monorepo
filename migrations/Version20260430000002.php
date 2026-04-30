<?php

declare(strict_types=1);

namespace App\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260430000002 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Vortos failed messages — dead letter queue for undeliverable events';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE IF NOT EXISTS vortos_failed_messages (
                id              UUID         PRIMARY KEY,
                transport_name  VARCHAR(255) NOT NULL,
                event_class     VARCHAR(512) NOT NULL,
                payload         TEXT         NOT NULL,
                headers         JSONB        NOT NULL DEFAULT '{}',
                failure_reason  TEXT         NOT NULL,
                exception_class VARCHAR(512) NOT NULL,
                attempt_count   INTEGER      NOT NULL DEFAULT 0,
                failed_at       TIMESTAMP    NOT NULL DEFAULT NOW(),
                replayed_at     TIMESTAMP,
                status          VARCHAR(20)  NOT NULL DEFAULT 'failed'
            )
        SQL);

        $this->addSql(<<<'SQL'
            CREATE INDEX IF NOT EXISTS idx_vortos_failed_messages_status
                ON vortos_failed_messages (status, failed_at)
                WHERE status = 'failed'
        SQL);
    }

    public function down(Schema $schema): void
    {
        $this->throwIrreversibleMigrationException(
            'Dropping the failed messages table destroys dead letter records. Perform manually if intentional.',
        );
    }
}
