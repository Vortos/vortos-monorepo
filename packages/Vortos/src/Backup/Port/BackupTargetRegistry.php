<?php

declare(strict_types=1);

namespace Vortos\Backup\Port;

use Psr\Container\ContainerInterface;
use Vortos\OpsKit\Driver\TaggedDriverRegistry;

/**
 * Resolves a {@see BackupTargetInterface} by its #[AsDriver] key (`postgres`, `mongo`).
 * The map is built at compile time by CollectBackupTargetsPass — zero runtime reflection.
 */
final class BackupTargetRegistry extends TaggedDriverRegistry
{
    public function __construct(ContainerInterface $drivers)
    {
        parent::__construct('backup_target', $drivers);
    }

    public function target(string $key): BackupTargetInterface
    {
        $target = $this->get($key);
        \assert($target instanceof BackupTargetInterface);

        return $target;
    }
}
