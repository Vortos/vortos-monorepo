<?php

declare(strict_types=1);

namespace Vortos\Deploy\Preflight\Check;

use Vortos\Deploy\Preflight\PreflightCategory;
use Vortos\Deploy\Preflight\PreflightCheckInterface;
use Vortos\Deploy\Preflight\PreflightContext;
use Vortos\Deploy\Preflight\PreflightFinding;
use Vortos\Deploy\Strategy\DeployStrategyRegistry;
use Vortos\Deploy\Target\DeployTargetRegistry;
use Vortos\OpsKit\Driver\Capability\CapabilityMismatchException;
use Vortos\OpsKit\Driver\Capability\CapabilityValidator;

/**
 * Fail-closed: the selected strategy's required capabilities must be a subset of what
 * the selected target declares it supports. This is the *same* {@see
 * CapabilityValidator} the config layer uses — single source of truth — so doctor
 * surfaces the identical, actionable mismatch (e.g. 'rolling' on a single-VPS target
 * that declares 'rolling_across_nodes=false').
 */
final class CapabilityDescriptorCheck implements PreflightCheckInterface
{
    public function __construct(
        private readonly DeployTargetRegistry $targets,
        private readonly DeployStrategyRegistry $strategies,
    ) {}

    public function id(): string
    {
        return 'capability.strategy_supported';
    }

    public function category(): PreflightCategory
    {
        return PreflightCategory::Capability;
    }

    public function check(PreflightContext $context): PreflightFinding
    {
        $def = $context->definition;

        if (!$this->targets->has($def->host) || !$this->strategies->has($def->strategy)) {
            // DriverSetCheck owns the "not registered" failure; this gate cannot run.
            return PreflightFinding::fail(
                $this->id(),
                $this->category(),
                'cannot validate capabilities: target or strategy not registered',
                sprintf('host=%s strategy=%s', $def->host, $def->strategy->value),
                'Resolve the driver-set failure first.',
            );
        }

        $target = $this->targets->target($def->host);
        $strategy = $this->strategies->get($def->strategy);

        try {
            CapabilityValidator::assertSatisfies(
                $def->host,
                'deploy-target',
                $target->capabilities(),
                $strategy->requires(),
            );
        } catch (CapabilityMismatchException $e) {
            return PreflightFinding::fail(
                $this->id(),
                $this->category(),
                sprintf('strategy "%s" is not supported by target "%s"', $def->strategy->value, $def->host),
                $e->getMessage(),
                'Choose a strategy the target supports, or select a target that declares the required capabilities.',
            );
        }

        return PreflightFinding::pass(
            $this->id(),
            $this->category(),
            sprintf('target "%s" supports strategy "%s"', $def->host, $def->strategy->value),
        );
    }
}
