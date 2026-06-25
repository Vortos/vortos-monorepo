<?php

declare(strict_types=1);

namespace Vortos\Release\Audit;

final class NullReleaseAuditEmitter implements ReleaseAuditEmitterInterface
{
    public function emit(ReleaseAuditEvent $event): void
    {
    }
}
