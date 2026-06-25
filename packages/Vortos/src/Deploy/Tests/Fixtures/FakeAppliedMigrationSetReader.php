<?php

declare(strict_types=1);

namespace Vortos\Deploy\Tests\Fixtures;

use Vortos\Release\Migration\AppliedMigrationSetReaderInterface;
use Vortos\Release\Schema\SchemaFingerprint;

final class FakeAppliedMigrationSetReader implements AppliedMigrationSetReaderInterface
{
    public function __construct(private readonly SchemaFingerprint $applied)
    {
    }

    public function currentApplied(): SchemaFingerprint
    {
        return $this->applied;
    }
}
