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
     * Create a container WITHOUT starting it.
     *
     * The point-in-time drill cannot use {@see run()}: PostgreSQL has to find its data directory
     * already populated at the instant it boots, so the base backup and the recovery configuration
     * must be written into a container that exists but is not yet running. Splitting create from
     * start is what makes that ordering expressible.
     */
    public function create(ContainerSpec $spec): ContainerHandle;

    /** Start a container created by {@see create()}. */
    public function start(ContainerHandle $handle): void;

    /**
     * Extract a tar stream into $path inside the container — the Engine API's equivalent of
     * `docker cp`, and the only way to place bytes inside a container without a bind mount, a
     * shared volume, or a shell in the image.
     *
     * Chunks are streamed with chunked transfer encoding rather than buffered, because the largest
     * thing this carries is a multi-hundred-megabyte base backup whose decrypted length is not
     * known until the last byte has been read.
     *
     * @param iterable<string> $tarChunks a well-formed tar archive, in order
     */
    public function putArchive(ContainerHandle $handle, string $path, iterable $tarChunks): void;

    /**
     * Container output produced at or after $sinceUnixSeconds, demultiplexed to plain text.
     *
     * The PITR drill reads this as a REQUEST CHANNEL, not for diagnostics: the `restore_command`
     * running inside a PHP-less PostgreSQL image announces the WAL segment it needs on stderr, and
     * this is how the drill hears it. That makes this method load-bearing rather than incidental,
     * and it is why the frame headers must be stripped properly instead of approximately.
     */
    public function logsSince(ContainerHandle $handle, int $sinceUnixSeconds): string;

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
