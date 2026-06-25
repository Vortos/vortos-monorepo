<?php

declare(strict_types=1);

namespace Vortos\Health\Uptime;

use Psr\Container\ContainerInterface;
use Vortos\OpsKit\Driver\TaggedDriverRegistry;

final class UptimeMonitorRegistry extends TaggedDriverRegistry
{
    public function __construct(ContainerInterface $drivers)
    {
        parent::__construct('uptime_monitor', $drivers);
    }

    public function monitor(string $key): UptimeMonitorInterface
    {
        /** @var UptimeMonitorInterface */
        return $this->get($key);
    }
}
