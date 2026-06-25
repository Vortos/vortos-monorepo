<?php

declare(strict_types=1);

namespace Vortos\Migration\Tests\Fixtures;

use Vortos\Migration\Attribute\DeployPhase;
use Vortos\Migration\Schema\MigrationPhase;

#[DeployPhase(MigrationPhase::Contract)]
final class FakeContractMigration
{
    public function up(): void
    {
        // $this->addSql('ALTER TABLE users DROP COLUMN email_old');
    }
}
