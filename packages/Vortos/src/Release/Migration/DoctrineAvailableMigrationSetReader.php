<?php

declare(strict_types=1);

namespace Vortos\Release\Migration;

use Vortos\Migration\Service\DependencyFactoryProviderInterface;
use Vortos\Release\Schema\SchemaFingerprint;

final class DoctrineAvailableMigrationSetReader implements AvailableMigrationSetReaderInterface
{
    public function __construct(
        private readonly DependencyFactoryProviderInterface $factoryProvider,
    ) {}

    public function availableSet(): SchemaFingerprint
    {
        $factory = $this->factoryProvider->create();
        $available = $factory->getMigrationPlanCalculator()->getMigrations();

        $ids = [];
        foreach ($available->getItems() as $migration) {
            $ids[] = (string) $migration->getVersion();
        }

        return new SchemaFingerprint($ids);
    }
}
