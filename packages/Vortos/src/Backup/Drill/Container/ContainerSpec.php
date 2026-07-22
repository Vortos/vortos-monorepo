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
         * tmpfs mount for the database's data directory. A drill database is write-heavy, entirely
         * disposable, and read exactly once — putting it in RAM makes the restore markedly faster and,
         * more importantly, means a container that escapes teardown leaves nothing on disk to reclaim.
         */
        public ?string $tmpfsPath = null,
        public int $tmpfsSizeBytes = 1073741824,
    ) {}
}
