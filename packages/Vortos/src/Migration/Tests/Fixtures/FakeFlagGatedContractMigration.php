<?php

declare(strict_types=1);

namespace Vortos\Migration\Tests\Fixtures;

use Vortos\Migration\Attribute\DeployPhase;
use Vortos\Migration\Attribute\GatedByFlag;
use Vortos\Migration\Schema\MigrationPhase;

#[DeployPhase(MigrationPhase::Contract)]
#[GatedByFlag(flagName: 'drop-email-old', oldVariant: 'legacy')]
final class FakeFlagGatedContractMigration
{
    public function up(): void
    {
        // $this->addSql('ALTER TABLE users DROP COLUMN email_old');
    }
}
