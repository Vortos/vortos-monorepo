<?php

declare(strict_types=1);

namespace Vortos\Paddle\AuditLog;

interface PaddleAuditLogWriterInterface
{
    public function record(PaddleAuditEntry $entry): void;
}
