<?php

declare(strict_types=1);

namespace Vortos\Foundation\Health\Contract;

/**
 * Where a legacy {@see HealthCheckInterface} belongs once bridged into vortos-health.
 *
 * This exists in Foundation rather than reusing `Vortos\Health\Probe\ProbeKind` because the
 * dependency runs one way — vortos-health requires vortos-foundation, never the reverse — so the
 * attribute that declares the intent cannot name the enum that ultimately expresses it.
 * {@see \Vortos\Health\DependencyInjection\Compiler\BridgeLegacyHealthChecksPass} maps between them.
 *
 * Only the two kinds a dependency check can sensibly claim are modelled. Liveness and Startup are
 * deliberately absent: a check that reaches OUT to a dependency can answer neither "is this process
 * wedged" nor "has this process finished booting", which are both questions about the process itself.
 */
enum HealthCheckKind: string
{
    /**
     * The check gates traffic: a failure marks the instance NOT ready and the load balancer takes it
     * out of the pool.
     *
     * Correct only for a dependency the process cannot serve ANY meaningful request without — its own
     * database, its own cache. Reserve it for those.
     */
    case Readiness = 'readiness';

    /**
     * The check is observed but never gates traffic: it is sampled by the monitor tick and surfaced
     * at /health/monitor, and is excluded from /health/live|ready|startup.
     *
     * This is the right kind for every SHARED, EXTERNAL dependency — object storage, a mail provider,
     * a third-party API. The reason is correlated failure: such a dependency is reached by every
     * replica at once, so gating readiness on it converts a partial degradation of one subsystem into
     * a total outage of the service. Every instance fails its probe simultaneously, the pool empties,
     * and requests that never needed the dependency stop being served too.
     *
     * The failure of the dependency itself belongs where it can be handled per-operation — a circuit
     * breaker around the client, so calls that need it fast-fail while everything else keeps serving.
     */
    case Monitoring = 'monitoring';
}
