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
