<?php

declare(strict_types=1);

namespace Vortos\Alerts\Escalation;

final readonly class AckTokenPayload
{
    public function __construct(
        public string $fingerprint,
        public int $tier,
        public int $expiresAt,
    ) {}
}
