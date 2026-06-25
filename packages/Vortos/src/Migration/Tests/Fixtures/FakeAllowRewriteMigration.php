<?php

declare(strict_types=1);

namespace Vortos\Migration\Tests\Fixtures;

use Vortos\Migration\Attribute\AllowFullTableRewrite;
use Vortos\Migration\Attribute\DeployPhase;
use Vortos\Migration\Schema\MigrationPhase;

#[DeployPhase(MigrationPhase::Contract)]
#[AllowFullTableRewrite]
final class FakeAllowRewriteMigration
{
    public function up(): void
    {
        // $this->addSql('UPDATE users SET email_new = email');
    }
}
