<?php

declare(strict_types=1);

namespace Vortos\Observability\Audit;

use Vortos\Domain\Event\EventEnvelope;

interface DeployAuditProjectorInterface
{
    public function apply(EventEnvelope $envelope): void;
}
