<?php

declare(strict_types=1);

namespace App\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260523000001 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add handler_id column to vortos_failed_messages';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE vortos_failed_messages ADD COLUMN IF NOT EXISTS handler_id VARCHAR(512) NOT NULL DEFAULT ''");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE vortos_failed_messages DROP COLUMN IF EXISTS handler_id');
    }
}
