<?php

declare(strict_types=1);

namespace Vortos\Migration\Safety;

use Vortos\Migration\Service\DependencyFactoryProviderInterface;

final class PendingMigrationVersionProvider implements PendingMigrationVersionProviderInterface
{
    public function __construct(
        private readonly DependencyFactoryProviderInterface $factoryProvider,
    ) {}

    /**
     * @return list<string>
     */
    public function getPending(): array
    {
        $factory = $this->factoryProvider->create();
        $available = $factory->getMigrationRepository()->getMigrations();
        $executed = $factory->getMetadataStorage()->getExecutedMigrations();
        $pending = $available->newMigrations($executed);

        return array_map(
            static fn ($item) => (string) $item->getVersion(),
            $pending->getItems(),
        );
    }

    /**
     * @return list<string>
     */
    public function getAll(): array
    {
        $factory = $this->factoryProvider->create();
        $available = $factory->getMigrationRepository()->getMigrations();

        return array_map(
            static fn ($item) => (string) $item->getVersion(),
            $available->getItems(),
        );
    }
}
