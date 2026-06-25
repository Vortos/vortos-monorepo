<?php

declare(strict_types=1);

namespace Vortos\Alerts\Notifier;

use Psr\Container\ContainerInterface;
use Vortos\OpsKit\Driver\TaggedDriverRegistry;

/**
 * Resolves a {@see NotifierInterface} driver by its stable key. Backed by a
 * compile-time-collected ServiceLocator; only installed drivers register, with zero
 * runtime reflection (Golden Rule #1).
 */
final class NotifierRegistry extends TaggedDriverRegistry
{
    public function __construct(ContainerInterface $drivers)
    {
        parent::__construct('alerts_notifier', $drivers);
    }

    public function notifier(string $key): NotifierInterface
    {
        /** @var NotifierInterface */
        return $this->get($key);
    }
}
