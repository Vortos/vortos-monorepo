<?php

declare(strict_types=1);

namespace Vortos\Alerts\Tests\Fixtures;

use Vortos\Alerts\AlertDispatcherInterface;
use Vortos\Alerts\Dedupe\DedupeDecision;
use Vortos\Alerts\DispatchResult;
use Vortos\Alerts\Event\AlertEvent;

final class FakeAlertDispatcher implements AlertDispatcherInterface
{
    /** @var list<AlertEvent> */
    private array $dispatched = [];

    public function dispatch(AlertEvent $event, ?array $routingOverride = null): DispatchResult
    {
        $this->dispatched[] = $event;

        return new DispatchResult(DedupeDecision::New, []);
    }

    /** @return list<AlertEvent> */
    public function dispatched(): array
    {
        return $this->dispatched;
    }
}
