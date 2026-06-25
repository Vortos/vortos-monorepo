<?php

declare(strict_types=1);

namespace Vortos\Iac\Lifecycle\Audit;

final class NullIacAuditSink implements IacAuditSinkInterface
{
    public function record(LifecycleEvent $event): void
    {
    }
}
