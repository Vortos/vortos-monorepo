<?php

declare(strict_types=1);

namespace Vortos\Migration\Service;

use Doctrine\DBAL\Connection;
use Doctrine\Migrations\Configuration\Connection\ExistingConnection;
use Doctrine\Migrations\Configuration\Migration\PhpFile;
use Doctrine\Migrations\DependencyFactory;

/**
 * Builds and caches a Doctrine Migrations DependencyFactory.
 *
 * Reads config from {project_root}/migrations.php and reuses the shared
 * DBAL Connection registered by PersistenceDbal — no second connection is opened.
 *
 * Lazy: the factory is created on first call to create(), not at container compile time,
 * so no DB connection is attempted until a migration command actually runs.
 */
final class DependencyFactoryProvider
{
    private ?DependencyFactory $factory = null;

    public function __construct(
        private readonly Connection $connection,
        private readonly string $projectDir,
    ) {}

    public function create(): DependencyFactory
    {
        if ($this->factory === null) {
            $this->factory = DependencyFactory::fromConnection(
                new PhpFile($this->projectDir . '/migrations.php'),
                new ExistingConnection($this->connection),
            );
        }

        return $this->factory;
    }
}
