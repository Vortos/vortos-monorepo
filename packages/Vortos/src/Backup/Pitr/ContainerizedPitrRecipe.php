<?php

declare(strict_types=1);

namespace Vortos\Backup\Pitr;

use Vortos\Backup\Console\BackupWalRestoreCommand;

use Vortos\Backup\Console\BackupRunCommand;
use Vortos\Backup\Console\BackupWalArchiveCommand;
use Vortos\Backup\Domain\BackupKind;

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
            'PITR_RECIPE.md' => $this->readme($vol, $postgresService, $backendService, $environment),
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
        //
        // The command name is taken from the command class, never written by hand. The emitted
        // script previously called `vortos:backup:wal-archive`, which does not exist — the command
        // is registered as `backup:wal-archive`. Every invocation failed with Symfony's
        // "command not found" help text, so not one WAL segment was ever shipped off-host while
        // the shipper looked perfectly healthy and segments piled up on local disk.
        $command = BackupWalArchiveCommand::NAME;
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
                if php bin/console $command "\$seg" --env="\$ENV"; then
                    rm -f "\$seg"
                fi
            done
            sleep "\$INTERVAL"
        done

        SH;
    }

    private function baseBackup(string $env, int $interval): string
    {
        // The kind comes from BackupKind, never a literal. The emitted script used `--kind=base`,
        // which is not a member of that enum — backup:run rejected it on every single iteration.
        // Paired with the `|| true` below, that produced a base-backup worker which looked alive,
        // logged nothing anyone read, and had never once written a base backup. Without base
        // backups the shipped WAL segments are unrestorable, so PITR was decorative end to end.
        $kind = BackupKind::PhysicalBase->value;
        // Same drift class as the shipper: the emitted script invoked `vortos:backup:run`, which is
        // not the registered name, so every base backup died on "command not found". Taken from the
        // command class so it cannot diverge again.
        $runCommand = BackupRunCommand::NAME;
        // No memory_limit override: S3CompatibleObjectStore buffers each multipart part through
        // php://temp, so an upload costs bounded RAM regardless of how large the backup is. The
        // emitted script deliberately does NOT raise the limit — doing so would hide a regression
        // in that guarantee behind a number that has to keep growing with the database.

        return <<<SH
        #!/bin/sh
        # Vortos scheduled base backup — runs in the backend/app image.
        set -eu
        ENV="$env"
        INTERVAL="$interval"

        while true; do
            # `|| true` keeps the supervision loop alive across a transient failure, but the
            # failure must not be silent: without this echo a permanently broken base backup is
            # indistinguishable from a healthy one. Backup freshness alerting is what turns this
            # line into a page.
            php bin/console $runCommand --env="\$ENV" --kind=$kind \
                || echo "vortos: base backup FAILED (env=\$ENV kind=$kind) — PITR is not restorable until this succeeds" >&2
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

    private function readme(string $vol, string $postgres, string $backend, string $env): string
    {
        // Built from the command's own constant so the runbook cannot drift from the registered
        // command name — the shipper script once hard-coded a name that had never existed, and
        // every invocation failed silently for weeks.
        $restoreCommand = sprintf('php bin/console %s %%f %%p --env=%s', BackupWalRestoreCommand::NAME, $env);

        return <<<MD
        # Containerized PITR recipe

        Postgres (`$postgres`, a PHP-less image) archives WAL to the shared `wal_archive` volume
        via a pure `cp` archive_command. The `wal-shipper` worker (extends `$backend`, so it has
        the Vortos CLI) ships segments off-host with `backup:wal-archive`; `base-backup`
        takes periodic base backups.

        ## Apply
        1. Merge `docker-compose.pitr.yaml` into your production compose.
        2. Ship `docker/postgres/postgresql.conf` and the two worker scripts.
        3. Recreate the stack; confirm segments appear in `$vol` and are shipped, then removed.

        The archive_command never invokes PHP, so it works with any stock Postgres image.

        ## The environment name must match your catalog — check this before trusting PITR

        These scripts were generated with `--env=$env`. **That string is a namespace in the backup
        catalog, not a label.** WAL segments and base backups shipped under `$env` are only visible
        to a restore that asks for `$env`.

        If your scheduled logical backups run under a different name — `production` while these say
        `prod` is the easy mistake, since they are generated independently — you get two disjoint
        catalogs. Every component reports success, `backup:list` looks healthy for the environment
        you happen to query, and the mismatch surfaces only during a restore, when the base backup
        the WAL needs is filed under a name nobody is looking in.

        Verify with one command before you rely on any of this:

        ```
        php bin/console backup:list --engine=postgres --env=$env
        ```

        It must show BOTH `physical_base` and `wal_segment` entries alongside your `logical_full`
        ones. If it shows only logical backups, the environment names do not agree — regenerate
        with the correct `--env` rather than editing the scripts by hand, so the next regeneration
        does not undo the fix.

        ## Recovering — restore_command

        WAL segments are encrypted at rest on the same seam as base backups, so recovery must fetch
        them through the Vortos CLI rather than copying them out of the object store. Recovery runs
        in an image that has the CLI (extend `$backend`, as the shipper does), NOT in the stock
        Postgres image.

        After restoring a base backup into the data directory, write into `postgresql.auto.conf`:

        ```
        restore_command = '$restoreCommand'
        recovery_target_time = '2026-01-01 12:00:00+00'
        recovery_target_action = 'promote'
        ```

        then `touch recovery.signal` and start Postgres.

        A non-zero exit from `restore_command` is how Postgres learns the archive is exhausted, so
        the last segment request failing is normal and marks the end of recovery — not an error.
        A missing decryption key, by contrast, fails loudly and refuses to write ciphertext into
        `pg_wal`, because Postgres would otherwise accept the file and report a damaged archive,
        which sends you looking at the wrong problem.
        MD;
    }
}
