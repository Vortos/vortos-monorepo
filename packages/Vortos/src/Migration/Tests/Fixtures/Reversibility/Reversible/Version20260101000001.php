<?php

declare(strict_types=1);

namespace Vortos\Migration\Tests\Fixtures\Reversibility\Reversible;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260101000001 extends AbstractMigration
{
    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE rv_things (id INT PRIMARY KEY, cfg JSONB NOT NULL)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE rv_things');
    }
}
