<?php

declare(strict_types=1);

namespace App\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260505114122 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create feature flags';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE IF NOT EXISTS feature_flags (
    id          VARCHAR(36)  NOT NULL,
    name        VARCHAR(255) NOT NULL,
    description TEXT         NOT NULL DEFAULT \'\',
    enabled     SMALLINT     NOT NULL DEFAULT 0,
    rules       TEXT         NOT NULL DEFAULT \'[]\',
    variants    TEXT         DEFAULT NULL,
    created_at  TIMESTAMP    NOT NULL,
    updated_at  TIMESTAMP    NOT NULL,
    PRIMARY KEY (id),
    UNIQUE (name)
)');
    }

    public function down(Schema $schema): void
    {
        $this->throwIrreversibleMigrationException(
            'This migration was generated from a module SQL stub and has no automatic rollback.'
        );
    }
}