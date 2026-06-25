<?php

declare(strict_types=1);

namespace Vortos\Migration\Safety;

use Psr\Container\ContainerInterface;
use Vortos\OpsKit\Driver\TaggedDriverRegistry;

final class MigrationSafetyAnalyzerRegistry extends TaggedDriverRegistry
{
    public function __construct(ContainerInterface $drivers)
    {
        parent::__construct('migration-safety', $drivers);
    }

    public function analyzer(string $key): MigrationSafetyAnalyzerInterface
    {
        /** @var MigrationSafetyAnalyzerInterface */
        return $this->get($key);
    }
}
