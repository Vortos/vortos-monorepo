# vortos/vortos-backup

Enterprise-grade database backups for the Vortos platform (Deploy/CI-CD Block 19).

`dump → store → verify → catalog → retain`, versioned and scheduled, behind the
OpsKit swappable-driver pattern. The reference stack backs up Postgres + Mongo to
Cloudflare R2 via the existing object store — but no provider name appears outside a
`Driver/` namespace.

## Concerns (two ports)

| Port | Drivers (in-core) | Selected by |
|------|-------------------|-------------|
| `Port\BackupTargetInterface` (dump source) | `postgres`, `mongo` | `#[AsDriver]` key |
| `Port\BackupStoreInterface` (destination)  | `object-store`       | `#[AsDriver]` key |

Both extend `OpsKit\DriverInterface`, so every driver reports a `CapabilityDescriptor`
validated at config time and asserted by the TCK (`Testing\*ConformanceTestCase`).

## Guarantees

- **Streamed, bounded memory.** Dumps flow process → store via multipart; the checksum
  is computed in the same pass (`Service\ChecksumStreamFilter`). No whole-artifact
  buffering; no plaintext dump on a tracked/persistent disk path.
- **Verified at creation.** `Service\IntegrityVerifier` reads the stored object back,
  re-checks the checksum (constant-time) and sniffs the format magic. A corrupt or
  truncated dump fails loudly and is never cataloged as good. A mid-stream subprocess
  failure is caught by `Service\Process\ProcessGuard` after the bytes are consumed.
- **Append-only catalog.** `Catalog\Dbal*` INSERTs once; a DB trigger rejects UPDATE
  (DELETE is permitted only for retention).
- **Safe retention.** `Domain\RetentionPolicy` is GFS with a hard `minKeepFloor` and a
  "never delete the most-recent" guard; dry-run is the default (`backup:retention`),
  `--apply` required to delete. WAL is pruned by the PITR invariant (kept iff ≥ the
  oldest retained base backup).
- **Full PITR.** `pg_basebackup` base backups + WAL shipping (`Pitr\PostgresWalArchiver`,
  idempotent). `Pitr\PitrPreflight` fails closed when the host isn't configured.

## Block boundaries

- **Block 17 (`vortos-alerts`)** — not required. Backup events broadcast on
  `Event\BackupEventSinkInterface` (logging default). A failed/integrity-failed backup
  is a `Critical` event; Block 17 registers an alerting sink via the
  `vortos.backup.event_sink` tag — zero changes here.
- **Block 20 (encryption/3-2-1/object-lock/drills)** — additive. At-rest envelope
  encryption slots into `Service\EncryptionSeam\StreamTransformInterface` (today an
  identity transform); immutability/cross-region are already declared store
  capabilities (honestly `false` for now).

## Host wiring (operator)

- **Schedule:** `backup:schedule` emits a managed cron fragment that invokes
  `backup:run`. The framework runs no scheduler.
- **PITR:** set `archive_mode=on`, `wal_level=replica`, and
  `archive_command = 'vortos backup:wal-archive %p --env=prod'` on the host. Verify
  with `Pitr\PitrPreflight`.

## Environment

| Var | Default | Purpose |
|-----|---------|---------|
| `VORTOS_BACKUP_STORE` | `object-store` | store driver key |
| `VORTOS_BACKUP_KEY_PREFIX` | `backups` | object key prefix |
| `VORTOS_BACKUP_LOCK_DIR` | `<project>/var/backup-locks` | single-flight locks |
| `VORTOS_BACKUP_MONGO_URI` | _(empty)_ | mongodump `--uri` |

## pgBackRest (physical backups and the WAL archive)

`config/backup.php` declares the repository and its schedules; the repository itself (bucket, cipher,
retention, compression) is pgBackRest's own configuration, baked into the database image.

```php
->pgBackRest(stanza: 'main', databaseService: 'write_db', repositoryEnvFile: '/opt/app/pgbackrest.env')
->schedule(fn ($s) => $s
    ->pgBackRestFull('0 2 * * 0')            // weekly full
    ->pgBackRestDifferential('0 2 * * 1-6')  // daily, changed blocks only
    ->pgBackRestVerify('30 4 * * 0')         // re-read the repository against its checksums
    ->pgBackRestDrill('30 1 * * *'))         // restore + replay to the end of the archive
```

- pgBackRest runs **inside the database container**; the backup node drives it with Docker exec through
  the least-privilege proxy (`VORTOS_BACKUP_DRILL_DOCKER_HOST`) and never holds the repository credential.
  Exec is detached and polled, so no proxy idle timeout can cut a long backup.
- Expiry is pgBackRest's own, run at the end of every backup — there is no retention schedule.
- The **drill** starts a disposable container from the database's own image, mounts the credential
  read-only, restores as the server user with `archive_mode=off` (a promoted drill never pushes a timeline
  into the production repository), waits for promotion, then runs the same invariants as every drill plus
  `wal_replayed`, which must reach the newest segment archived when the drill began.
- The **`pgbackrest-repository` probe** (Monitoring) pages when the repository is unreadable or not ok,
  when the newest backup or full is older than its schedule allows, or when the server reports segments
  archived that the repository never stored (`archive-push-queue-max` overflow).
- The recovery-time projection anchors on the newest pgBackRest backup when pgBackRest is declared.
- Operator: `backup:pgbackrest status|info|check|backup --type=full|diff|verify|drill`.
