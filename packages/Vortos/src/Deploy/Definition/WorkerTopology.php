<?php

declare(strict_types=1);

namespace Vortos\Deploy\Definition;

/**
 * How background workers are rolled during a deploy (B20).
 *
 * The framework historically emitted a supervisorctl-driven RollWorkers phase from every strategy,
 * which assumes a persistent supervisord reachable from where the deploy runs. In the blessed
 * edge-router / compose-color topology the deploy runs in a throwaway one-shot with no supervisord,
 * and the workers already ride the color as compose-managed worker-<color> services — so that phase
 * both fails (supervisorctl exit 7) and is redundant. This enum makes the worker model explicit
 * instead of assumed.
 */
enum WorkerTopology: string
{
    /**
     * Workers are compose-managed worker-<color> services, brought up with the color by
     * StartContainer and torn down with it on cutover. No separate supervisorctl rollout phase is
     * emitted. This is the default for the ssh-compose / deploy-in-image path.
     */
    case RideColor = 'ride-color';

    /**
     * A persistent supervisord (reachable from where the deploy runs) owns the worker processes; the
     * strategy emits the drain/restart RollWorkers phase driven by the worker controller.
     */
    case ExternalSupervisor = 'external-supervisor';

    public function ridesColor(): bool
    {
        return $this === self::RideColor;
    }

    public function usesExternalSupervisor(): bool
    {
        return $this === self::ExternalSupervisor;
    }
}
