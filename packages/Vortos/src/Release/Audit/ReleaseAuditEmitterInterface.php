<?php

declare(strict_types=1);

namespace Vortos\Release\Audit;

interface ReleaseAuditEmitterInterface
{
    public function emit(ReleaseAuditEvent $event): void;
}
