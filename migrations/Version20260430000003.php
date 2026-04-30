<?php

declare(strict_types=1);

namespace App\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260430000003 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create users table';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE users (
                id            VARCHAR(36)  NOT NULL PRIMARY KEY,
                name          VARCHAR(255) NOT NULL,
                email         VARCHAR(255) NOT NULL UNIQUE,
                password_hash VARCHAR(255) NOT NULL DEFAULT '',
                roles         JSONB        NOT NULL DEFAULT '["ROLE_USER"]',
                status        BOOLEAN      NULL,
                version       INTEGER      NOT NULL DEFAULT 0
            )
        SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE users');
    }
}
