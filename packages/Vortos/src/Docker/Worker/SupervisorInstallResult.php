<?php

declare(strict_types=1);

namespace Vortos\Docker\Worker;

final readonly class SupervisorInstallResult
{
    public function __construct(
        public SupervisorPlan $plan,
        public bool $written,
    ) {}
}
