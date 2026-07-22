<?php

declare(strict_types=1);

namespace Vortos\Backup\Drill\Container;

/**
 * The minimum container lifecycle a restore drill needs: bring up a disposable database, then
 * guarantee it goes away again.
 *
 * Deliberately tiny and free of Docker vocabulary at the seam, for two reasons. It keeps
 * {@see \Vortos\Backup\Drill\Driver\Postgres\ContainerizedDatabaseProvisioner} testable without a
 * daemon (the drill logic is the part worth testing; the HTTP plumbing is not), and it leaves room for
 * a Podman or Kubernetes-Job implementation later without touching the drill at all.
 */
interface ContainerRuntimeInterface
{
    /** Ensure an image is present locally, pulling it if necessary. */
    public function ensureImage(string $image): void;

    /** Create and start a container, returning a handle to it. */
    public function run(ContainerSpec $spec): ContainerHandle;

    /**
     * Stop and remove a container along with its anonymous volumes. MUST be idempotent and MUST NOT
     * throw when the container is already gone — teardown runs in a `finally` and must never mask the
     * drill's own outcome.
     */
    public function remove(ContainerHandle $handle): void;

    /**
     * Remove every container carrying $label, except $exceptId. The safety net for the case teardown
     * cannot cover: a hard kill (OOM, SIGKILL, host reboot) between `run()` and `remove()` leaves an
     * orphan running forever, quietly consuming memory and holding a database port. Sweeping on the
     * way *in* means the next drill cleans up after the last one even if that one never got to.
     *
     * @return int the number of containers removed
     */
    public function removeOrphans(string $label, ?string $exceptId = null): int;
}
