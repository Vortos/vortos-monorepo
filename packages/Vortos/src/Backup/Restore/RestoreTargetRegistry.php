<?php

declare(strict_types=1);

namespace Vortos\Backup\Restore;

use Psr\Container\ContainerInterface;
use Vortos\OpsKit\Driver\TaggedDriverRegistry;

final class RestoreTargetRegistry extends TaggedDriverRegistry
{
    public function __construct(ContainerInterface $drivers)
    {
        parent::__construct('restore_target', $drivers);
    }

    public function target(string $key): RestoreTargetInterface
    {
        $target = $this->get($key);
        \assert($target instanceof RestoreTargetInterface);

        return $target;
    }
}
