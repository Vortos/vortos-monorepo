<?php

declare(strict_types=1);

namespace Vortos\Backup\Pitr;

/**
 * Emits a containerized point-in-time-recovery (WAL shipping) recipe.
 *
 * The default archiver runs `vortos backup:wal-archive %p` as Postgres' archive_command, which
 * assumes the Vortos CLI (PHP) is available where Postgres runs. In a containerized Compose
 * deployment Postgres runs in a PHP-less image (e.g. postgres:18-alpine), so archive_command
 * cannot invoke the CLI directly (upstream P3-1).
 *
 * The recipe decouples the two responsibilities:
 *  1. Postgres archives WAL segments to a SHARED VOLUME using a pure `cp` archive_command
 *     (no PHP needed in the database image).
 *  2. A `wal-shipper` worker — running in the app/backend image, mounting the same volume —
 *     ships archived segments to the off-host backup store via `vortos backup:wal-archive`, and a
 *     scheduled `base-backup` worker takes periodic base backups.
 *
 * All artifacts are returned as (relative path => contents); nothing is written here.
 */
final class ContainerizedPitrRecipe
{
    /**
     * @param string $walVolume      shared volume mount path visible to both Postgres and the shipper
     * @param string $backendService the Compose service (app image) that owns the shipper/base-backup workers
     * @param string $postgresService the Compose service running Postgres
     * @param string $environment     environment name passed to backup:wal-archive
     * @return array<string, string>
     */
    public function generate(
        string $walVolume = '/wal_archive',
        string $backendService = 'backend',
        string $postgresService = 'postgres',
        string $environment = 'prod',
        int $baseBackupIntervalSeconds = 86_400,
        int $shipIntervalSeconds = 15,
    ): array {
        $vol = rtrim($walVolume, '/');

        return [
            'docker/postgres/postgresql.conf' => $this->postgresConf($vol),
            'docker/backup/wal-shipper.sh' => $this->walShipper($vol, $environment, $shipIntervalSeconds),
            'docker/backup/base-backup.sh' => $this->baseBackup($environment, $baseBackupIntervalSeconds),
            'docker-compose.pitr.yaml' => $this->composeFragment($vol, $backendService, $postgresService),
            'PITR_RECIPE.md' => $this->readme($vol, $postgresService, $backendService),
        ];
    }

    private function postgresConf(string $vol): string
    {
        // Pure `cp` archive_command — no PHP in the Postgres image. The `test !` guard keeps it
        // idempotent per the archive_command contract (never overwrite an already-archived seg).
        return <<<CONF
        # Vortos containerized PITR — Postgres archives WAL to a shared volume ($vol).
        # A separate wal-shipper worker moves segments to the off-host backup store.
        wal_level = replica
        archive_mode = on
        archive_command = 'test ! -f $vol/%f && cp %p $vol/%f'
        archive_timeout = 60
        max_wal_senders = 3

        CONF;
    }

    private function walShipper(string $vol, string $env, int $interval): string
    {
        // Runs in the app image (has the Vortos CLI). Ships each archived segment, then removes
        // the local copy only after backup:wal-archive confirms durable off-host storage.
        return <<<SH
        #!/bin/sh
        # Vortos WAL shipper — runs in the backend/app image, mounts $vol read-write.
        set -eu
        WAL_DIR="$vol"
        ENV="$env"
        INTERVAL="$interval"

        while true; do
            for seg in "\$WAL_DIR"/0*; do
                [ -e "\$seg" ] || continue
                if php bin/console vortos:backup:wal-archive "\$seg" --env="\$ENV"; then
                    rm -f "\$seg"
                fi
            done
            sleep "\$INTERVAL"
        done

        SH;
    }

    private function baseBackup(string $env, int $interval): string
    {
        return <<<SH
        #!/bin/sh
        # Vortos scheduled base backup — runs in the backend/app image.
        set -eu
        ENV="$env"
        INTERVAL="$interval"

        while true; do
            php bin/console vortos:backup:run --env="\$ENV" --kind=base || true
            sleep "\$INTERVAL"
        done

        SH;
    }

    private function composeFragment(string $vol, string $backend, string $postgres): string
    {
        return <<<YAML
        # Vortos containerized PITR — merge into your production compose.
        # Postgres archives WAL to the shared `wal_archive` volume; the shipper worker (app image)
        # ships them off-host, and base-backup takes periodic base backups.
        volumes:
          wal_archive:

        services:
          $postgres:
            volumes:
              - wal_archive:$vol
              - ./docker/postgres/postgresql.conf:/etc/postgresql/postgresql.conf:ro
            command: ["postgres", "-c", "config_file=/etc/postgresql/postgresql.conf"]

          wal-shipper:
            extends:
              service: $backend
            command: ["sh", "docker/backup/wal-shipper.sh"]
            volumes:
              - wal_archive:$vol
            restart: unless-stopped

          base-backup:
            extends:
              service: $backend
            command: ["sh", "docker/backup/base-backup.sh"]
            restart: unless-stopped

        YAML;
    }

    private function readme(string $vol, string $postgres, string $backend): string
    {
        return <<<MD
        # Containerized PITR recipe

        Postgres (`$postgres`, a PHP-less image) archives WAL to the shared `wal_archive` volume
        via a pure `cp` archive_command. The `wal-shipper` worker (extends `$backend`, so it has
        the Vortos CLI) ships segments off-host with `vortos:backup:wal-archive`; `base-backup`
        takes periodic base backups.

        ## Apply
        1. Merge `docker-compose.pitr.yaml` into your production compose.
        2. Ship `docker/postgres/postgresql.conf` and the two worker scripts.
        3. Recreate the stack; confirm segments appear in `$vol` and are shipped, then removed.

        The archive_command never invokes PHP, so it works with any stock Postgres image.
        MD;
    }
}
