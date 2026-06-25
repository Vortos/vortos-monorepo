<?php
declare(strict_types=1);

namespace Vortos\Auth\RateLimit;

final class RateLimitFailureConfig
{
    public function __construct(
        public readonly RateLimitFailureMode $ipMode = RateLimitFailureMode::FailClosed,
        public readonly RateLimitFailureMode $globalMode = RateLimitFailureMode::FailClosed,
        public readonly RateLimitFailureMode $userMode = RateLimitFailureMode::FailOpen,
        public readonly int $circuitBreakerThreshold = 5,
        public readonly int $circuitBreakerResetSeconds = 30,
    ) {}

    public function modeForScope(RateLimitScope $scope): RateLimitFailureMode
    {
        return match ($scope) {
            RateLimitScope::Ip     => $this->ipMode,
            RateLimitScope::Global => $this->globalMode,
            RateLimitScope::User   => $this->userMode,
        };
    }
}
