<?php

declare(strict_types=1);

namespace Vortos\Backup\Port;

use Psr\Container\ContainerInterface;
use Vortos\OpsKit\Driver\TaggedDriverRegistry;

/**
 * Resolves a {@see BackupStoreInterface} by its #[AsDriver] key (`object-store`).
 * The map is built at compile time by CollectBackupStoresPass — zero runtime reflection.
 */
final class BackupStoreRegistry extends TaggedDriverRegistry
{
    public function __construct(ContainerInterface $drivers)
    {
        parent::__construct('backup_store', $drivers);
    }

    public function store(string $key): BackupStoreInterface
    {
        $store = $this->get($key);
        \assert($store instanceof BackupStoreInterface);

        return $store;
    }
}
