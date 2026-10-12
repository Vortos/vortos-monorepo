<?php

declare(strict_types=1);

namespace Vortos\Backup\Drill\Container;

/**
 * What to run for a drill: an image, its environment, the network it must be reachable on, and the
 * labels that make it sweepable.
 */
final readonly class ContainerSpec
{
    /**
     * @param array<string, string> $env
     * @param array<string, string> $labels
     * @param list<string>          $command
     * @param array<string, string> $readOnlyBinds host path => container path
     */
    public function __construct(
        public string $image,
        public string $name,
        public array $env = [],
        public array $labels = [],
        public ?string $network = null,
        /**
         * Overrides the image CMD. Server-level tuning must arrive this way rather than through
         * environment: postmaster-level settings such as `fsync` cannot be changed per session, so
         * passing them via PGOPTIONS makes the image's own initdb step fail with
         * "parameter fsync cannot be changed now" and the container exits during setup.
         */
        public array $command = [],
        /**
         * Overrides the image ENTRYPOINT. The PITR drill needs this: the official PostgreSQL image
         * entrypoint assumes it is initialising or starting a cluster it owns, whereas a recovery
         * drill has to fix up ownership on a data directory that arrived from somewhere else before
         * handing over to that entrypoint. An empty list leaves the image's own ENTRYPOINT alone.
         *
         * @var list<string>
         */
        public array $entrypoint = [],
        /**
         * tmpfs mount for the database's data directory. A drill database is write-heavy, entirely
         * disposable, and read exactly once — putting it in RAM makes the restore markedly faster and,
         * more importantly, means a container that escapes teardown leaves nothing on disk to reclaim.
         */
        public ?string $tmpfsPath = null,
        public int $tmpfsSizeBytes = 1073741824,
        /**
         * Size of the container's /dev/shm. PostgreSQL allocates parallel-query DSM segments there
         * (`dynamic_shared_memory_type = posix`), so a cluster restored from a production base
         * inherits that setting and can outgrow Docker's 64 MiB default. Left at the default here
         * to match the production database container, which runs with exactly that and is the
         * standing proof the value is adequate for this workload.
         */
        public int $shmSizeBytes = 67108864,
        /**
         * The user the container's first process runs as (`uid:gid`); null keeps the image's USER. The
         * pgBackRest drill starts as root only to read a root-only repository credential, then drops to
         * the server's own user before restoring anything.
         */
        public ?string $user = null,
        /**
         * Host files mounted read-only. The pgBackRest drill receives the repository credential this
         * way so the process that orchestrates the drill never holds it: the Engine API's `Env` would
         * require the caller to read the secret first.
         */
        public array $readOnlyBinds = [],
    ) {
        foreach ($readOnlyBinds as $host => $target) {
            if (!str_starts_with($host, '/') || !str_starts_with($target, '/') || str_contains($host . $target, ':') || str_contains($host . $target, '..')) {
                throw new \InvalidArgumentException(sprintf('A read-only bind must map an absolute host path to an absolute container path, got "%s" => "%s".', $host, $target));
            }
        }
    }
}
