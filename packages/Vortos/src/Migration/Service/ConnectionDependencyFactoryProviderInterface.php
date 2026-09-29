<?php

declare(strict_types=1);

namespace Vortos\Migration\Service;

use Doctrine\DBAL\Connection;
use Doctrine\Migrations\DependencyFactory;

/**
 * A Doctrine Migrations factory for a connection other than the application's own.
 *
 * For tools that must run the project's migrations exactly as `vortos:migrate` runs them, but
 * against a database of their own — down-verify's disposable database. Never cached: each
 * connection is its own world, and handing back a factory bound to a previous one would migrate
 * the wrong database.
 */
interface ConnectionDependencyFactoryProviderInterface
{
    public function forConnection(Connection $connection): DependencyFactory;
}
