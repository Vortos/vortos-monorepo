<?php

declare(strict_types=1);

namespace Vortos\Iac\Lifecycle\Audit;

interface IacAuditSinkInterface
{
    public function record(LifecycleEvent $event): void;
}
