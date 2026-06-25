<?php

declare(strict_types=1);

namespace Vortos\Iac\Lifecycle\Policy;

final readonly class PolicyViolation
{
    public function __construct(
        public string $ruleId,
        public string $address,
        public string $message,
    ) {
        if ($ruleId === '') {
            throw new \InvalidArgumentException('Policy violation rule ID must not be empty.');
        }
    }
}
