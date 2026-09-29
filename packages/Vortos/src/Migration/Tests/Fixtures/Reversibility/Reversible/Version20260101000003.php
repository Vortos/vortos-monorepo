<?php

declare(strict_types=1);

namespace Vortos\Migration\Tests\Fixtures\Reversibility\Reversible;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/** Alters a table an earlier migration created — so verifying it alone needs the earlier ones applied. */
final class Version20260101000003 extends AbstractMigration
{
    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE rv_things ADD COLUMN label VARCHAR(20) NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE rv_things DROP COLUMN label');
    }
}
