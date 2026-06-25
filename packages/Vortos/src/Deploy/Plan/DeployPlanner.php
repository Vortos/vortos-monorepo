<?php

declare(strict_types=1);

namespace Vortos\Deploy\Plan;

use Vortos\Deploy\Strategy\DeployStrategyRegistry;

final class DeployPlanner
{
    public function __construct(
        private readonly DeployStrategyRegistry $strategies,
        private readonly PhaseGate $phaseGate = new PhaseGate(),
        private readonly PhaseOrderPolicy $phaseOrderPolicy = new PhaseOrderPolicy(),
    ) {}

    public function plan(DeployContext $context): DeployPlan
    {
        $this->phaseGate->assertNoPendingContract($context->currentState);

        $strategy = $this->strategies->get($context->definition->strategy);
        $phases = $strategy->phases($context);

        $this->phaseOrderPolicy->assertValid($phases);

        return new DeployPlan(
            phases: $phases,
            definitionHash: $context->definition->definitionHash,
        );
    }
}
