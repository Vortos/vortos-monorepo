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

        // Outstanding (not-yet-executed) migrations. Doctrine's status calculator is the
        // supported API for this — AvailableMigrationsSet (returned by getMigrations()) has
        // no diff method, so the previous $available->newMigrations($executed) call was a hard
        // fatal for any pending migration and the CI gate could never run.
        $new = $factory->getMigrationStatusCalculator()->getNewMigrations();

        return array_map(
            static fn ($item) => (string) $item->getVersion(),
            $new->getItems(),
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
