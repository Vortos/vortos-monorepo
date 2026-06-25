<?php

declare(strict_types=1);

namespace Vortos\Deploy\Credential\Governance;

final readonly class EnvironmentProtectionConfig
{
    public function __construct(
        public string $environment,
        public bool $protected,
        public int $requiredApprovals = 1,
    ) {}
}
