<?php

declare(strict_types=1);

namespace Vortos\Deploy\Preflight\Check;

use Vortos\Deploy\Definition\WorkerTopology;
use Vortos\Deploy\Preflight\PreflightCategory;
use Vortos\Deploy\Preflight\PreflightCheckInterface;
use Vortos\Deploy\Preflight\PreflightContext;
use Vortos\Deploy\Preflight\PreflightFinding;

/**
 * Fail-closed worker-topology gate (B20).
 *
 * The supervisorctl-driven RollWorkers phase assumes a persistent supervisord reachable from where
 * the deploy runs. On the ssh-compose deploy-in-image path the deploy runs in a throwaway one-shot
 * that has no supervisord, so an external-supervisor topology there is guaranteed to fail at cutover
 * (supervisorctl exit 7 — the original context-less error). Catch that misconfiguration before the
 * cutover instead of at it.
 *
 * The hosts that run deploy-in-image (no ambient supervisord) are enumerated here; any other host is
 * assumed to have a reachable supervisord and is a Pass.
 */
final class WorkerTopologyCheck implements PreflightCheckInterface
{
    /** Hosts whose deploy runs in a throwaway one-shot with no supervisord. */
    private const SUPERVISORD_LESS_HOSTS = ['ssh-compose'];

    public function id(): string
    {
        return 'worker.topology_reachable';
    }

    public function category(): PreflightCategory
    {
        return PreflightCategory::Capability;
    }

    public function check(PreflightContext $context): PreflightFinding
    {
        $definition = $context->definition;

        if ($definition->workerTopology->ridesColor()) {
            return PreflightFinding::pass(
                $this->id(),
                $this->category(),
                'workers ride the compose color; no external supervisord required',
            );
        }

        if (in_array($definition->host, self::SUPERVISORD_LESS_HOSTS, true)) {
            return PreflightFinding::fail(
                $this->id(),
                $this->category(),
                'external-supervisor worker topology is unreachable on a deploy-in-image host',
                sprintf(
                    'host=%s topology=%s: the deploy runs in a throwaway one-shot with no supervisord, so '
                    . 'the supervisorctl RollWorkers phase cannot reach one (exit 7 at cutover).',
                    $definition->host,
                    $definition->workerTopology->value,
                ),
                sprintf(
                    'Use ->workerTopology(WorkerTopology::RideColor) in config/deploy.php so workers ride '
                    . 'the compose color, or deploy to a host that runs a persistent supervisord. (%s)',
                    WorkerTopology::RideColor->value,
                ),
            );
        }

        return PreflightFinding::pass(
            $this->id(),
            $this->category(),
            sprintf('external-supervisor topology on host "%s" (assumed reachable)', $definition->host),
        );
    }
}
