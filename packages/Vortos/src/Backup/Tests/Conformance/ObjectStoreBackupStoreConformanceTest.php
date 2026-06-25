<?php

declare(strict_types=1);

namespace Vortos\Backup\Tests\Conformance;

use Vortos\Backup\Driver\ObjectStore\ObjectStoreBackupStore;
use Vortos\Backup\Testing\BackupStoreConformanceTestCase;
use Vortos\Backup\Tests\Support\InMemoryObjectStore;
use Vortos\OpsKit\Driver\DriverInterface;

final class ObjectStoreBackupStoreConformanceTest extends BackupStoreConformanceTestCase
{
    protected function createDriver(): DriverInterface
    {
        return new ObjectStoreBackupStore(new InMemoryObjectStore());
    }

    protected function expectedKey(): string
    {
        return 'object-store';
    }
}
