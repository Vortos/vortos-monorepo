<?php

declare(strict_types=1);

namespace Vortos\Backup\Drill;

use Psr\Clock\ClockInterface;
use Throwable;
use Vortos\Backup\Catalog\BackupCatalogReadModelInterface;
use Vortos\Backup\Crypto\EnvelopeStreamCipher;
use Vortos\Backup\Domain\BackupArtifact;
use Vortos\Backup\Domain\BackupKind;
use Vortos\Backup\Domain\DatabaseEngine;
use Vortos\Backup\Event\BackupEvent;
use Vortos\Backup\Drill\Check\WalReplayedInvariant;
use Vortos\Backup\Event\BackupEventSinkInterface;
use Vortos\Backup\Pitr\PitrRecoveryRecorder;
use Vortos\Backup\Port\BackupStoreInterface;
use Vortos\Backup\Port\BackupStoreRegistry;
use Vortos\Backup\Restore\Capability\RestoreTargetCapability;
use Vortos\Backup\Restore\RestoreCoordinator;
use Vortos\Backup\Restore\Driver\Postgres\PostgresPitrRestoreTarget;
use Vortos\Backup\Restore\RestoreRequest;
use Vortos\Secrets\Key\KeyProviderInterface;

/**
 * Orchestrates a restore drill: provision → restore → invariant checks → teardown.
 * Measures RTO. Emits DrillSucceeded (Info) or DrillFailed (Critical).
 */
final class DrillRunner
{
    /**
     * @param list<InvariantCheck> $invariantChecks
     */
    public function __construct(
        private readonly BackupCatalogReadModelInterface $catalog,
        private readonly BackupStoreRegistry $stores,
        private readonly RestoreCoordinator $restoreCoordinator,
        private readonly DrillEnvironmentProvisionerInterface $provisioner,
        private readonly DrillReportStoreInterface $reportStore,
        private readonly BackupEventSinkInterface $events,
        private readonly ClockInterface $clock,
        private readonly array $invariantChecks,
        private readonly string $storeKey,
        private readonly ?KeyProviderInterface $keyProvider = null,
        /**
         * Provisions a cluster for a PHYSICAL restore: created but not started, so a base backup and
         * its recovery configuration can be written before the postmaster boots.
         *
         * Null when point-in-time drilling is not configured, and that is a hard gate rather than a
         * degradation — see {@see targetSupportsPointInTime()}. A base backup handed to the logical
         * provisioner would be restored into a running empty server, which cannot work and, worse,
         * would fail for a reason that says nothing about the backup.
         */
        private readonly ?DrillEnvironmentProvisionerInterface $pitrProvisioner = null,
    ) {}

    /**
     * @param BackupKind|null $onlyKind restrict the drill to one kind of artifact rather than taking
     *                                  the newest restorable one. A schedule uses this to say which
     *                                  RESTORE PATH it is proving: a daily logical drill and a weekly
     *                                  point-in-time drill answer different questions, and left to
     *                                  pick for itself the runner would always take whichever backup
     *                                  happened to be newer and silently stop exercising the other.
     */
    public function run(
        DatabaseEngine $engine,
        string $environment,
        bool $shallow = false,
        ?BackupKind $onlyKind = null,
    ): DrillReport
    {
        // Ask for the newest RESTORABLE artifact, never simply the newest row.
        //
        // Once continuous WAL archiving is on, a wal_segment lands roughly every sixty seconds, so
        // "the latest artifact" is almost always a single WAL segment — something no drill can
        // restore on its own. Drilling one would either fail for the wrong reason or, far worse,
        // report a pass having proved nothing about whether the database can actually be recovered.
        // A restore drill that can silently assert nothing is the most dangerous component in a
        // backup system, because it is the thing everyone points at to justify confidence.
        //
        // It must also never be a kind the configured restore target cannot consume.
        //
        // physical_base is a TAR OF A DATA DIRECTORY. Restoring it means laying the directory down
        // and replaying WAL — point-in-time recovery. The Postgres target restores through
        // `pg_restore`, a logical tool, and honestly declares PointInTime => false; handing it a
        // base backup fails for a reason that has nothing to do with whether the backup is good.
        //
        // This mattered the moment object-store streaming was fixed. Before that a 100 MB base
        // could not even be read back (it exhausted the memory limit), so the newest RESTORABLE
        // artifact was always a ~2.5 MB logical dump and the drill quietly stayed on the path that
        // worked. Fixing the read path would have made this pick the base instead and turned a
        // green weekly drill red — the fix breaking the alarm that was supposed to watch it.
        $candidateKinds = $this->candidateKinds($engine, $onlyKind);

        $artifact = $this->catalog->latestOfKind($engine, $environment, $candidateKinds);

        if ($artifact === null) {
            throw new \RuntimeException(sprintf(
                'No drillable backup artifact (%s) found for %s/%s — cannot drill. WAL segments '
                . 'alone are not restorable without a base, and physical_base is only drillable by '
                . 'a restore target declaring the point_in_time capability.',
                implode('/', array_map(static fn (BackupKind $k): string => $k->value, $candidateKinds)),
                $engine->value,
                $environment,
            ));
        }

        // A physical base is restored by an entirely different mechanism from a dump, and the choice
        // is driven by the ARTIFACT rather than by the caller's intent — so an unqualified drill that
        // happens to pick a base backup takes the point-in-time path too, instead of handing it to a
        // target that cannot consume it.
        $isPointInTime = $artifact->kind === BackupKind::PhysicalBase;
        $recorder = $isPointInTime ? new PitrRecoveryRecorder() : null;

        $start = $this->clock->now();
        $drillEnv = null;
        $store = $this->stores->store($this->storeKey);

        try {
            if ($shallow) {
                $this->shallowDecryptVerify($artifact, $store);
                $rtoMs = $this->elapsedMs($start);

                $report = new DrillReport(
                    $this->generateId(),
                    $engine,
                    $environment,
                    $artifact->id->value(),
                    $start,
                    $rtoMs,
                    'passed',
                    [InvariantResult::pass('shallow_decrypt', 'envelope header + AEAD decrypt verified')],
                    null,
                    $artifact->kind,
                );
            } else {
                $drillEnv = ($isPointInTime ? $this->requirePitrProvisioner() : $this->provisioner)
                    ->provision($engine);

                $options = $drillEnv->options;
                if ($recorder !== null) {
                    $options[PostgresPitrRestoreTarget::OPTION_RECORDER] = $recorder;
                }

                $this->restoreCoordinator->restore(
                    $artifact,
                    $store,
                    new RestoreRequest($drillEnv->dsn, options: $options),
                );

                $connParams = $this->parseConnParams($drillEnv->dsn);
                $results = [];
                foreach ($this->checksFor($recorder) as $check) {
                    $results[] = $check->check($connParams);
                }

                $rtoMs = $this->elapsedMs($start);
                $allPassed = array_reduce(
                    $results,
                    static fn (bool $carry, InvariantResult $r): bool => $carry && $r->passed,
                    true,
                );

                $report = new DrillReport(
                    $this->generateId(),
                    $engine,
                    $environment,
                    $artifact->id->value(),
                    $start,
                    $rtoMs,
                    $allPassed ? 'passed' : 'failed',
                    $results,
                    $allPassed ? null : 'One or more invariant checks failed.',
                    $artifact->kind,
                );
            }
        } catch (Throwable $e) {
            $rtoMs = $this->elapsedMs($start);
            $report = new DrillReport(
                $this->generateId(),
                $engine,
                $environment,
                $artifact->id->value(),
                $start,
                $rtoMs,
                'failed',
                [],
                $e->getMessage(),
                $artifact->kind,
            );
        } finally {
            if ($drillEnv !== null) {
                try {
                    $this->provisioner->teardown($drillEnv);
                } catch (Throwable) {
                    // best effort
                }
            }
        }

        $this->reportStore->save($report);

        if ($report->passed()) {
            $this->events->emit(BackupEvent::drillSucceeded($engine, $environment, $report->rtoMs, $this->clock->now()));
        } else {
            $this->events->emit(BackupEvent::drillFailed($engine, $environment, $report->error ?? 'invariant failure', $this->clock->now()));
        }

        return $report;
    }

    /**
     * decryptStream() authenticates every AEAD chunk synchronously inside its own read
     * loop before returning, so by the time it returns the envelope's integrity has
     * already been fully verified — there is no separate "header only" mode to add.
     * The plaintext is discarded immediately; it is never written to disk or restored.
     */
    private function shallowDecryptVerify(
        BackupArtifact $artifact,
        BackupStoreInterface $store,
    ): void {
        $raw = $store->open($artifact->storeKey);
        if (!is_resource($raw)) {
            throw new \RuntimeException('Cannot open artifact for shallow decrypt verify.');
        }

        try {
            if ($artifact->encryption !== null) {
                if ($this->keyProvider === null) {
                    throw new \RuntimeException(
                        'Cannot shallow-verify an encrypted artifact: no key provider configured.',
                    );
                }

                $cipher = new EnvelopeStreamCipher();
                $plaintext = $cipher->decryptStream($raw, fn ($wrapped) => $this->keyProvider->unwrap($wrapped));
                fclose($plaintext);
            }
        } finally {
            if (is_resource($raw)) {
                fclose($raw);
            }
        }
    }

    private function elapsedMs(\DateTimeImmutable $start): int
    {
        $now = $this->clock->now();
        $diff = (int) round(((float) $now->format('U.u') - (float) $start->format('U.u')) * 1000);

        return max(0, $diff);
    }

    private function generateId(): string
    {
        return 'drill-' . $this->clock->now()->format('Ymd\THis') . '-' . bin2hex(random_bytes(4));
    }

    /**
     * @return array<string, mixed>
     */
    private function parseConnParams(string $dsn): array
    {
        $parsed = parse_url($dsn);

        return [
            'host' => $parsed['host'] ?? 'localhost',
            'port' => $parsed['port'] ?? 5432,
            'user' => $parsed['user'] ?? 'postgres',
            'password' => isset($parsed['pass']) ? urldecode($parsed['pass']) : '',
            'dbname' => ltrim($parsed['path'] ?? '/postgres', '/'),
        ];
    }

    /**
     * Which kinds of artifact this drill may select.
     *
     * @return non-empty-list<BackupKind>
     */
    private function candidateKinds(DatabaseEngine $engine, ?BackupKind $onlyKind): array
    {
        if ($onlyKind !== null) {
            // An explicit request must fail loudly when it cannot be honoured. Quietly falling back
            // to the newest logical dump would leave a schedule named for a point-in-time drill
            // reporting green having never touched a base backup or a WAL segment — the failure
            // this whole capability gate exists to prevent, reintroduced one layer up.
            if ($onlyKind === BackupKind::PhysicalBase && !$this->supportsPointInTime($engine)) {
                throw new \RuntimeException(sprintf(
                    'A point-in-time drill was requested for %s but this installation cannot perform '
                    . 'one: it needs a restore target declaring the point_in_time capability AND a '
                    . 'provisioner able to start a cluster over a restored data directory. Configure '
                    . 'container-mode drills (VORTOS_BACKUP_DRILL_DOCKER_HOST) to enable it.',
                    $engine->value,
                ));
            }

            return [$onlyKind];
        }

        $candidateKinds = [BackupKind::LogicalFull, BackupKind::MongoArchive];

        if ($this->supportsPointInTime($engine)) {
            $candidateKinds[] = BackupKind::PhysicalBase;
        }

        return $candidateKinds;
    }

    /**
     * The invariants for this drill.
     *
     * A point-in-time drill gets one extra, and it is not optional garnish: every other invariant
     * passes just as happily on a base backup that started up having replayed no WAL at all, which
     * is a restore to the base's own instant rather than point-in-time recovery.
     *
     * @return list<InvariantCheck>
     */
    private function checksFor(?PitrRecoveryRecorder $recorder): array
    {
        $checks = $this->invariantChecks;

        if ($recorder !== null) {
            $checks[] = new WalReplayedInvariant($recorder);
        }

        return $checks;
    }

    private function requirePitrProvisioner(): DrillEnvironmentProvisionerInterface
    {
        if ($this->pitrProvisioner === null) {
            throw new \RuntimeException(
                'A physical base backup was selected for this drill but no point-in-time provisioner '
                . 'is configured. Restoring it through the logical provisioner is not a degraded '
                . 'drill — it cannot work, and it would fail for a reason unrelated to the backup.',
            );
        }

        return $this->pitrProvisioner;
    }

    /**
     * Can this installation actually perform a point-in-time (physical) restore?
     *
     * BOTH halves are required, and the second is easy to forget. The target's capability descriptor
     * says the restore MECHANISM exists; the provisioner says something can stand up a cluster for
     * it to restore into. A target registered without its provisioner would advertise the capability
     * and then fail at provision time — after the drill had already selected a base backup and
     * discarded the logical dump it could have proved something with.
     */
    private function supportsPointInTime(DatabaseEngine $engine): bool
    {
        if ($this->pitrProvisioner === null) {
            return false;
        }

        try {
            $target = $this->restoreCoordinator->targetFor($engine, BackupKind::PhysicalBase);
        } catch (\Throwable) {
            return false; // no target registered for this engine — nothing is drillable anyway
        }

        return $target->capabilities()->supports(RestoreTargetCapability::PointInTime);
    }
}
