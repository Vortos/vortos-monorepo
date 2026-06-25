<?php

declare(strict_types=1);

namespace Vortos\Analytics\Registry;

use Psr\Container\ContainerInterface;
use Vortos\Analytics\AnalyticsInterface;
use Vortos\OpsKit\Driver\TaggedDriverRegistry;

final class AnalyticsDriverRegistry extends TaggedDriverRegistry
{
    public function __construct(ContainerInterface $drivers)
    {
        parent::__construct('analytics', $drivers);
    }

    public function driver(string $key): AnalyticsInterface
    {
        /** @var AnalyticsInterface */
        return $this->get($key);
    }
}
