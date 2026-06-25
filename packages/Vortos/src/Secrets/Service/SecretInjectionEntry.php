<?php

declare(strict_types=1);

namespace Vortos\Secrets\Service;

use Vortos\Secrets\Value\SecretValue;

/** One `ENV_VAR ⇒ SecretValue` pair within a {@see SecretInjectionPlan}. */
final readonly class SecretInjectionEntry
{
    public function __construct(
        public string $envVar,
        public SecretValue $value,
    ) {}
}
