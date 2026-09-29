<?php

declare(strict_types=1);

namespace Vortos\Migration\Tests\Fixtures\Reversibility\Reversible;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/** Binds a value, as a seed migration does — the shape that extracted-SQL verification could not run. */
final class Version20260101000002 extends AbstractMigration
{
    public function up(Schema $schema): void
    {
        $this->addSql('INSERT INTO rv_things (id, cfg) VALUES (1, :cfg::jsonb)', ['cfg' => '{"seeded": true}']);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DELETE FROM rv_things WHERE id = :id', ['id' => 1]);
    }
}
