<?php

declare(strict_types=1);

namespace Vortos\Release\Migration;

use Vortos\Migration\Service\DependencyFactoryProviderInterface;
use Vortos\Release\Schema\SchemaFingerprint;

final class DoctrineAppliedMigrationSetReader implements AppliedMigrationSetReaderInterface
{
    public function __construct(
        private readonly DependencyFactoryProviderInterface $factoryProvider,
    ) {}

    public function currentApplied(): SchemaFingerprint
    {
        $factory = $this->factoryProvider->create();
        $storage = $factory->getMetadataStorage();
        $storage->ensureInitialized();

        $executed = $storage->getExecutedMigrations();
        $ids = [];

        foreach ($executed->getItems() as $migration) {
            $ids[] = (string) $migration->getVersion();
        }

        return new SchemaFingerprint($ids);
    }
}
