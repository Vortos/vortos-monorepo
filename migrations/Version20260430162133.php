<?php

declare(strict_types=1);

namespace App\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260430162133 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Vortos failed messages';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE IF NOT EXISTS vortos_failed_messages (
    id              UUID         PRIMARY KEY,
    transport_name  VARCHAR(255) NOT NULL,
    event_class     VARCHAR(512) NOT NULL,
    payload         TEXT         NOT NULL,
    headers         JSONB        NOT NULL DEFAULT \'{}\',
    failure_reason  TEXT         NOT NULL,
    exception_class VARCHAR(512) NOT NULL,
    attempt_count   INTEGER      NOT NULL DEFAULT 0,
    failed_at       TIMESTAMP    NOT NULL DEFAULT NOW(),
    replayed_at     TIMESTAMP,
    status          VARCHAR(20)  NOT NULL DEFAULT \'failed\'
)');
        $this->addSql('CREATE INDEX IF NOT EXISTS idx_vortos_failed_messages_status
    ON vortos_failed_messages (status, failed_at)
    WHERE status = \'failed\'');
    }

    public function down(Schema $schema): void
    {
        $this->throwIrreversibleMigrationException(
            'This migration was generated from a module SQL stub and has no automatic rollback.'
        );
    }
}