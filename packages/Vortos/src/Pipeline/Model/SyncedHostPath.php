<?php

declare(strict_types=1);

namespace Vortos\Pipeline\Model;

/**
 * A project file or directory that compose bind-mounts from the host, delivered from the signed release
 * image on every deploy (RC-3).
 *
 * WHY. The deploy synced only `docker-compose.prod.yaml`. Every relative bind mount in it resolved
 * against host files nothing ever updated: `write_db` mounted an init directory frozen since July,
 * missing the two scripts that grant replication access, so a fresh volume would have booted a primary
 * no backup could stream from — and nothing anywhere reported the difference. Declaring the path makes
 * the deploy copy it (sha256-compared, exactly {@see $mode} and {@see $owner}) before the topology that
 * references it is written, and lets the topology policy refuse any bind source that is not declared.
 *
 * Synced paths are always mounted read-only: the host copy is desired state from git, and a writable
 * mount would let a container turn it back into drift.
 *
 * No field has a default: mode, owner and audience are decisions, not omissions.
 */
final readonly class SyncedHostPath
{
    public function __construct(
        public ProjectPath $path,
        public SyncedFileMode $mode,
        public FileOwner $owner,
        public ComposeServiceSet $services,
    ) {}
}
