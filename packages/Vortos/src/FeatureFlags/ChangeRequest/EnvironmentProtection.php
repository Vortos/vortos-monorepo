<?php

declare(strict_types=1);

namespace Vortos\FeatureFlags\ChangeRequest;

final readonly class EnvironmentProtection
{
    public function __construct(
        public string $environment,
        public string $projectId,
        public bool $protected,
        public int $requiredApprovals,
        public bool $requireReason,
        public int $requestTtlSeconds,
    ) {}
}
