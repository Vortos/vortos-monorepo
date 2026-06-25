<?php

declare(strict_types=1);

namespace Vortos\Observability\Query;

use Psr\Container\ContainerInterface;
use Vortos\OpsKit\Driver\TaggedDriverRegistry;

final class MetricsQueryRegistry extends TaggedDriverRegistry
{
    public function __construct(ContainerInterface $drivers)
    {
        parent::__construct('metrics-query', $drivers);
    }

    public function query(string $key): MetricsQueryInterface
    {
        /** @var MetricsQueryInterface */
        return $this->get($key);
    }
}
