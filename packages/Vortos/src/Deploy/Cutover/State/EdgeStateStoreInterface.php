<?php

declare(strict_types=1);

namespace Vortos\Deploy\Cutover\State;

/**
 * Durable, cross-node source of truth for each environment's edge routing intent (GAP-D).
 *
 * The live cutover switch happens instantly via the Caddy Admin API; this store is what lets an edge
 * node that restarts, is replaced, or scales out **reconstruct** the active-color route on boot
 * instead of losing it (the old MountedConfigWriter persisted to /etc/caddy/, a path the deploy
 * one-shot could not even reach). Because it holds only routing metadata — not the rendered Caddy
 * config or any secret — it can live in a shared control-plane store (Redis by default) so a fleet of
 * stateless edge nodes all agree on which color is live.
 *
 * Implementations must persist atomically (a partially-written state must never be observable) and
 * maintain a monotonically increasing {@see EdgeState::$version} per env so concurrent deploys are
 * ordered and observable.
 */
interface EdgeStateStoreInterface
{
    /** The persisted edge state for the environment, or null when none has been recorded yet. */
    public function load(string $env): ?EdgeState;

    /**
     * Atomically persist the routing intent for its environment, bumping the stored version. The
     * returned state carries the assigned version + timestamp.
     */
    public function save(EdgeState $state): EdgeState;
}
