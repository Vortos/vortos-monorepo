<?php

declare(strict_types=1);

namespace Vortos\Migration\Tests\Fixtures;

use Vortos\Migration\Attribute\DeployPhase;
use Vortos\Migration\Schema\MigrationPhase;

#[DeployPhase(MigrationPhase::Expand)]
final class FakeExpandMigration
{
    public function up(): void
    {
        // $this->addSql('ALTER TABLE users ADD COLUMN email_new VARCHAR(255) NULL');
    }
}
