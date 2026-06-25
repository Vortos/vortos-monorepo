<?php

declare(strict_types=1);

namespace Vortos\Alerts;

use Vortos\Alerts\Event\AlertEvent;

/** The single entry point every upstream integration adapter calls to fire an alert. */
interface AlertDispatcherInterface
{
    /** @param list<string>|null $routingOverride explicit channel keys from the firing AlertRule, bypassing the matrix */
    public function dispatch(AlertEvent $event, ?array $routingOverride = null): DispatchResult;
}
