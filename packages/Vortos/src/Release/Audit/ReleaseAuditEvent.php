<?php

declare(strict_types=1);

namespace Vortos\Release\Audit;

final readonly class ReleaseAuditEvent
{
    public function __construct(
        public string $action,
        public string $transactionId,
        public string $tagName,
        public string $packageName,
        public string $sha,
        public \DateTimeImmutable $occurredAt,
    ) {}
}
