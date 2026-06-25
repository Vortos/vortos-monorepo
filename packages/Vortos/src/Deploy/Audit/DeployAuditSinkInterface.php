<?php

declare(strict_types=1);

namespace Vortos\Deploy\Audit;

use Vortos\Domain\Event\EventEnvelope;

/**
 * The seam Observability (or any other audit consumer) plugs into — Deploy never
 * depends on Observability (§11.3 dependency-direction rule); instead Deploy
 * declares this port and autoconfigures any implementing service, regardless of
 * which package registers it. Observability registers a sink implementing this
 * interface (guarded by class_exists, since Deploy is an optional integration
 * for Observability) and is wired in automatically by Symfony autoconfiguration —
 * no explicit cross-package tag is required.
 *
 * Implementations must be best-effort from the *deploy's* point of view: an
 * exception here is caught and logged by {@see DeployAuditRecorder}, never allowed
 * to fail or block the deploy itself.
 */
interface DeployAuditSinkInterface
{
    public function handle(EventEnvelope $envelope): void;
}
