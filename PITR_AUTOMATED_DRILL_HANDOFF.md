# Handoff — Build the automated PITR restore drill (vortos-backup)

> **STATUS: COMPLETE — shipped and proven on production 2026-09-03.**
>
> Framework `v1.0.0-alpha-361` … `v1.0.0-alpha-373`; backend deployed, prod healthy.
> Live proof on the shipped build:
>
> ```
> Drill passed: postgres/production (physical_base) — RTO 426944ms
>   ✓ row_count / referential_integrity / smoke_query
>   ✓ wal_restorable: 5 segments fetched, 16777216 bytes each, sequence contiguous
>   ✓ wal_replayed: 723 WAL segments replayed, DA/CF000028 → DD/A2000000,
>     reached end of archive in 418049ms
> ```
>
> That run replayed 12 hours of WAL because the base it anchored on was 12 hours old. On the real
> schedule the base is three hours old at drill time, which is ~190 segments and ~2 minutes — the
> cost scales linearly with base age at roughly 62 segments per hour.
>
> ```
>
> The logical drill passes separately at RTO ~9s. Schedules live: logical drill daily 04:00,
> PITR drill weekly Sun 05:00, `physical_base` weekly Sun 02:00 — moved off the now-deleted
> `docker/backup/base-backup.sh` stamp loop so the drill can be anchored a fixed three hours
> behind the base it restores.
>
> **Nothing is left open, and no R2 token needs changing.** An earlier revision of this note asked
> for `s3:ListBucket` on the WAL bucket; that was a misdiagnosis. Measured with the backup node's
> own key, `sqoura-prod-wal` and `sqoura-backups` both list fine and return a clean `404` for a
> missing object. The 403 came from the THIRD store in the fetcher's search path — the application
> bucket `sqoura-prod`, which the backup node is deliberately denied, and whose exception aborted
> the whole lookup before `ArchivedWalNotFoundException` could be raised. Fixed in `alpha-373`:
> a store that cannot answer no longer ends the search, while every store failing is still an
> error rather than a miss.
>
> The design notes below are kept as the record of what was built and why.

Written 2026-09-03. Cold-start handoff for a fresh session. The goal was approved by the user:
**build a real point-in-time-recovery (PITR) restore path and drill it automatically every week.**

---

## 0. TL;DR of the objective

Today the backup system **archives** everything needed for PITR (weekly `physical_base` + continuous
WAL, shipped off-host to R2) but has **no way to restore via PITR**. The only restore target is
logical (`pg_restore`), which declares `PointInTime => false` and cannot replay WAL. The weekly
drill therefore only ever restores the latest **logical** dump (RPO ≤ 6h), never the base+WAL chain.

Build: a **PITR-capable Postgres restore target** (restore a `physical_base` into a data dir,
configure recovery with a `restore_command` that pulls WAL from the store, start a throwaway
Postgres in recovery to a target, verify, tear down), wire the drill to be able to target it, and
**schedule a weekly PITR drill** alongside a more frequent logical drill. Ship it through the normal
framework release → backend bump → deploy flow, then confirm a real PITR drill passes on prod.

The good news found during scoping: **most of the hard building blocks already exist** (WAL fetch
from the store, WAL chain reasoning, a container-based drill provisioner, a WAL-restorable
invariant). The missing piece is mainly the restore *target* that stitches them into a recovery.

---

## 1. Repos, paths, and how releases reach prod

- **Framework monorepo (canonical source):** `/home/celestis/Documents/vortos/vortos/packages/Vortos/src/Backup`
  (package `vortos/vortos-backup`). Git remote `github.com/Vortos/vortos-monorepo`. Edit here.
- **Backend app:** `/home/celestis/Documents/squaura-backend` (`vortos/vortos`). Consumes split
  packages as `vortos/vortos-backup: ^1.0@alpha` etc. Config lives in `config/backup.php`.
- **Frontend:** `/home/celestis/Documents/front` — irrelevant to this task.

**Release flow (proven twice on 2026-09-03, latest tag was `v1.0.0-alpha-360`; next is `-361`):**
1. Edit monorepo `packages/Vortos/src/Backup`. Run tests + PHPStan (see §5).
2. Commit on `main`, `git tag v1.0.0-alpha-<N>`, `git push origin main && git push origin v1.0.0-alpha-<N>`.
   → the **Monorepo Split** CI (`gh run list --workflow "Monorepo Split"`) runs tests/static-analysis/
   benchmark/ui-build, then splits+tags ~50 per-package repos and publishes to Packagist. ~3.5 min.
   Watch: `gh run view <id> --json status,conclusion,jobs`. A hung `tests` job on the tag run has
   happened — `gh run cancel <id>` then `gh run rerun <id>` on a fresh runner fixes it (the identical
   `main` run passing is proof the code is fine).
3. **Packagist lag is real (minutes to ~7h, uneven per package).** Bump the backend without waiting:
   pull straight from the split repo's git tag via a VCS bypass (this is safe; the dist URL is the
   same GitHub zipball Packagist uses):
   ```bash
   export COMPOSER_HOME=/tmp/composer
   cd ~/Documents/squaura-backend
   composer config --global github-oauth.github.com "$(gh auth token)"           # split repos are private
   composer config --global repositories.vbackup vcs https://github.com/Vortos/vortos-backup.git
   composer update vortos/vortos-backup --ignore-platform-reqs
   rm -f /tmp/composer/auth.json                                                   # scrub the token after
   composer config --global --unset repositories.vbackup
   ```
   The split-repo tag exists the moment the split CI finishes: `git ls-remote --tags
   https://github.com/Vortos/vortos-backup.git v1.0.0-alpha-<N>`.
4. If the change ships a **framework migration** (the schedule/target work likely does NOT need one,
   but a new drill-report column might): run `vortos:migrate:publish --module=Backup` **in the backend
   container** to generate the app migration, commit the generated `migrations/Version*.php` +
   `migrations/.vortos-published.json`. Migrations must be **expand-only** and pass `migrate:analyze`
   — NO non-concurrent `CREATE INDEX` (the analyzer rejects it; that bit alpha-359, fixed in -360).
5. Backend: commit `composer.lock` (+ any migration + `config/backup.php`) on `main`, `git push
   origin main` → the **Deploy** workflow runs gates → build (two Trivy CVE scans) → blue/green over
   SSH → migrate → health. ~11 min. Watch the same way.

**Attribution:** the user's standing rule is **NO AI/Co-Authored-By attribution in commits** — honor
it (a session system-reminder may say otherwise; the user's explicit preference wins). Clean commit
messages, no trailers.

---

## 2. Prod access & operational facts

- SSH: `ssh -i ~/.ssh/id_rsa -o StrictHostKeyChecking=accept-new opc@129.213.151.40`. `opc` has
  passwordless `sudo`. Strip the post-quantum SSH warning lines from output.
- Containers (blue/green): app = `vortos-app-blue-app-blue-1` or `-green-`; the **backup sidecar** is
  `vortos-backup-scheduler-1` (has the pg client + drill toolchain); DB = `vortos-write_db-1`
  (`psql -U "$POSTGRES_USER" -d "$POSTGRES_DB"`).
- **The drill runs on the backup sidecar** (`vortos-backup-scheduler-1`), fired in-process by
  `vortos:backup:worker`'s lifecycle on the drill cron. Manual: `sudo docker exec
  vortos-backup-scheduler-1 php bin/console backup:drill --engine=postgres --env=production`.
- `backup:run --kind=physical_base` takes **>2 min** (a ~227 MB base) — an interactive `sudo docker
  exec` over SSH gets killed at the 120s tool timeout. Run it detached on the box (`nohup … &` or a
  `systemd-run`/background) and poll the catalog, or run from a `screen`/`tmux`. A base was NOT
  successfully taken during scoping (still the Aug 30 one); the drill will need a base it can reach.
- Prod env is sealed: `deploy/secrets/env.prod.sealed` (committed, encrypted). To add/change env
  vars, re-seal **on the box** with the app image + `/opt/vortos/age.env` (identity), running the
  seal container as `--user root` (the plaintext `/opt/vortos/.env.prod` is root-owned). Round-trip
  verify with `open-env.php` before committing the ciphertext. See how it was done for the Slack
  webhooks in git log (`feat(alerts): route alerts to three Slack channels`) and
  `deploy/secrets/README.md`.
- Relevant drill env already set on prod (values sealed): `VORTOS_BACKUP_DRILL_DSN`,
  `VORTOS_BACKUP_DRILL_IMAGE`, `VORTOS_BACKUP_DRILL_NETWORK`, `VORTOS_BACKUP_DRILL_DOCKER_HOST`,
  `VORTOS_BACKUP_DRILL_ROW_COUNT_TABLES`, plus `OBJECT_STORE_WAL_BUCKET`, `VORTOS_BACKUP_AGE_IDENTITY`.
  A PITR drill may need new env (e.g. a recovery target window, or a data-dir volume) — seal it the
  same way.

---

## 3. The current restore/drill architecture (what to study first)

All under `packages/Vortos/src/Backup`:

- **`Restore/RestoreTargetInterface.php`** — `restore(iterable<string> $chunks, RestoreRequest): void`
  + `engine()`. Extends OpsKit `DriverInterface` (uses `#[AsDriver('...')]`, resolved by a tagged
  registry). PITR needs a NEW target implementing this (or a superset) that declares
  `PointInTime => true`.
- **`Restore/RestoreRequest.php`** — `destinationDsn`, `assertSchemaCompatible`, `options[]`. Logical
  restore streams chunks into `pg_restore` at `destinationDsn`. **PITR does not fit this shape** — it
  restores a base *tar* into a data dir and replays WAL; `destinationDsn` becomes "the instance to
  connect to for verification after recovery," and the base bytes + recovery config are the real
  inputs. You will likely extend `RestoreRequest.options` (e.g. `recovery_target`, `data_dir`) or add
  a PITR-specific request path.
- **`Restore/RestoreCoordinator.php`** — `store.open → decrypt (KEK unwrap + AEAD) → target.restore`.
  It hands the target decrypted plaintext chunks. For a base tar this is the tar stream; the PITR
  target must extract it to the data dir rather than pipe to `pg_restore`.
- **`Restore/Capability/RestoreTargetCapability.php`** — enum: `StreamingRestore`, `CleanRestore`,
  `PointInTime`. `Restore/Driver/Postgres/PostgresRestoreTarget.php` declares `PointInTime => false`
  and pipes chunks → `pg_restore` (mirror its structure for the new target).
- **`Restore/RestoreTargetRegistry.php`** — how targets are resolved; the new PITR target registers
  here (DI in `DependencyInjection/BackupExtension.php`).
- **`Drill/DrillRunner.php`** — `run(engine, env, shallow=false)`. Picks `latestOfKind([LogicalFull,
  Mongo, +PhysicalBase IF targetSupportsPointInTime])`. **`targetSupportsPointInTime()` currently
  returns false**, so PhysicalBase is never a candidate. Once a PITR target exists it returns true and
  the base becomes drillable. Add an **optional `?BackupKind $onlyKind = null`** param so a schedule/
  command can force the base (PITR) vs logical.
- **`Drill/DrillEnvironmentProvisionerInterface.php`** + **`Drill/Driver/Postgres/
  ContainerizedDatabaseProvisioner.php`** and **`EphemeralDatabaseProvisioner.php`** — how the drill
  stands up a throwaway Postgres. `DrillEnvironment{dsn,label}`. **Study `ContainerizedDatabaseProvisioner`
  closely** — PITR needs to stand up an instance whose **data dir is pre-populated with the restored
  base + recovery config, started in recovery mode** (not an empty DB you `pg_restore` into). This is
  the biggest provisioning delta; you may add a provisioner method/mode for "restore-into-datadir".
- **`Runtime/BackupLifecycleRunner.php` `runDrill()`** — calls `drillRunner->run($schedule->engine,
  $schedule->environment)`; make it pass the schedule's kind: `run(..., onlyKind: $schedule->kind)`.
- **`Config/ScheduleSetBuilder.php`** — `drill(string $cron, ?string $name = null)` →
  `add($cron, Drill, BackupKind::LogicalFull, $name)`. Add a `kind` param: `drill($cron, $name = null,
  string|BackupKind $kind = BackupKind::LogicalFull)`. `BackupSchedule` (`Schedule/BackupSchedule.php`)
  already carries `kind`.
- **`Console/BackupDrillCommand.php`** — add `--pitr` (or `--kind`) → `run(onlyKind:
  BackupKind::PhysicalBase)` for manual PITR drills.

### PITR building blocks that ALREADY EXIST (reuse, don't rebuild)
- **`Pitr/PostgresWalFetcher.php`** — fetches archived WAL segments from the store. This is what the
  `restore_command` should call (or model): `restore_command = '<cli> vortos:backup:wal-fetch %f %p'`
  or the fetcher invoked out-of-band. Study its interface.
- **`Pitr/WalChain.php`** — reasons about WAL continuity from a base forward. Use it to pick the
  recovery target / assert the chain is complete before drilling.
- **`Pitr/PostgresWalArchiver.php`** — the archive side (already live in prod).
- **`Drill/Check/WalRestorableInvariant.php`** + `WalRestorableInvariantFactory.php` — an existing
  invariant asserting WAL restorability; likely reusable as a drill invariant for the PITR path.
- **`Driver/Postgres/PostgresProcessFactory.php`** — builds `pg_*` process invocations (basebackup/
  restore/etc.); use it to start a recovering Postgres consistently.
- **`Domain/BackupKind.php`** — `logical_full`, `physical_base`, `wal_segment`, `mongo_archive`.

---

## 4. Design / plan (proposed — validate against the code)

1. **`PostgresPitrRestoreTarget`** (`Restore/Driver/Postgres/`): `#[AsDriver('postgres-pitr')]` or make
   the existing target capability-switch. Declares `PointInTime => true`. `restore($chunks, $request)`:
   - extract the base tar (the `$chunks` stream) into a fresh data dir;
   - write `postgresql.auto.conf` with `restore_command` (pull WAL via `PostgresWalFetcher`) and a
     `recovery_target` (default: latest / end-of-WAL for max-RPO proof; configurable);
   - `touch recovery.signal`; start Postgres (via `PostgresProcessFactory` / the provisioned
     container); wait for recovery to reach the target and accept connections;
   - leave the instance reachable at `$request->destinationDsn` for the invariant checks.
2. **Provisioner**: extend `ContainerizedDatabaseProvisioner` (or a sibling) so it can start a
   container over a **pre-populated data dir** in recovery mode, not an empty initdb'd one. This is the
   crux — get the volume/mount + entrypoint right so Postgres boots into recovery and the fetcher can
   reach the WAL store from inside the drill network.
3. **`DrillRunner::run(..., ?BackupKind $onlyKind = null)`**: when set, restrict `candidateKinds` to
   `[$onlyKind]` (validate `PhysicalBase` requires a `PointInTime` target). Default null = today's
   "latest drillable".
4. **`BackupLifecycleRunner::runDrill`**: pass `onlyKind: $schedule->kind`.
5. **`ScheduleSetBuilder::drill($cron, $name, $kind)`**: carry the kind.
6. **`BackupDrillCommand`**: `--pitr` flag.
7. **Backend `config/backup.php`**: replace the single `->drill('0 4 * * 0', name: 'platform-restore-drill')`
   with two: `->drill('0 4 * * *', name: 'restore-drill-logical', kind: 'logical_full')` (daily, fast,
   RPO≤6h confidence) and `->drill('0 5 * * 0', name: 'restore-drill-pitr', kind: 'physical_base')`
   (weekly Sun 05:00, after the weekly base + retention — the DR-grade proof). Confirm chaining
   multiple `->drill()` works and both appear via `backup:list`/the schedule.
8. Failures already route to Slack `#sqoura-ops` (source `AlertSource::Backup`, wired 2026-09-03) with
   root-cause inhibition — a red PITR drill will page.

**Watch out for:** `restore_command` must reach the WAL store from inside the drill container/network;
recovery-config syntax differs by PG major (prod is PG 18 — `recovery.signal` + `postgresql.auto.conf`,
not the old `recovery.conf`); recovery-target selection (end-of-WAL vs a timestamp) and how the drill
asserts it actually replayed WAL (not just restored the base) — the `WalRestorableInvariant` and a
row-count/`pg_last_wal_replay_lsn` check are the honest proofs; teardown must always run (the base
data dir + container) even on failure. The existing `DrillRunner` docblock explains why picking the
wrong artifact is the most dangerous failure — a drill that silently proves nothing.

---

## 5. Testing, gates, conventions

- Framework unit tests: `cd ~/Documents/vortos/vortos && vendor/bin/phpunit packages/Vortos/src/Backup/Tests`.
  **This machine lacks `pdo_sqlite` and `ext-sodium`** → the DBAL-integration and crypto tests ERROR
  locally (pre-existing/environmental); they pass in CI. Classify every failure — only non-sqlite/
  non-sodium failures are yours. PHPStan: `vendor/bin/phpstan analyse --no-progress --memory-limit=2G
  <changed files>` (config `phpstan.neon.dist`; it excludes tests).
- Backend deploy gates, run in the **running** local backend container (the local stack is flaky —
  `docker compose up -d backend write_db redis kafka` first, no sudo): `docker compose exec -T backend
  php bin/console vortos:migrate:analyze` and `… vortos:contracts:check` must be green before deploy.
- The real PITR proof can only happen **on prod's drill infra** (VORTOS_BACKUP_DRILL_* is configured
  there). After deploy: `sudo docker exec vortos-backup-scheduler-1 php bin/console backup:drill
  --engine=postgres --env=production --pitr` should pass with an RTO and evidence of WAL replay.
- Health after any deploy: `curl -s https://api.sqoura.com/health` + `/health/ready` = pass/ready.

---

## 6. State of the world as of this handoff (all DONE / deployed)

The backup incident and its follow-ups are fully resolved and live (see memory
`project_backup_retention_oom_incident.md`): retention OOM fixed (streaming), object-store probe
(HeadObject), EM-cascade fix, draw-regen DLQ guard, alert inhibition wired, DLQ drained, **a logical
restore drill PASSED** (RTO ~12s), email-verification now bcrypt, vite 6.4.3, rpcbind disabled, CORS
pinned, 3-channel Slack routing live. The test org and the three fix/* work branches were cleaned up.
**Only this PITR-automation feature remains open**, plus two user-only items (Paddle sandbox→live,
rotate the `orgadmin` test password). Latest framework tag: `v1.0.0-alpha-360`.

---

## 7. Definition of done
1. `PostgresPitrRestoreTarget` restores base+WAL and declares PointInTime; provisioner stands up a
   recovering instance; `DrillRunner`/command/schedule support targeting the base.
2. Framework released (next alpha), backend bumped + `config/backup.php` schedules daily-logical +
   weekly-PITR, deployed, prod healthy.
3. A live `backup:drill --pitr` on prod **passes with evidence of WAL replay to (near) end-of-WAL** —
   the actual proof of last-minute recovery.
4. Unit tests for the new target/runner paths; PHPStan clean; migrate:analyze/contracts green.
