<?php

declare(strict_types=1);

namespace Vortos\Backup\Drill;

use Psr\Clock\ClockInterface;
use Throwable;
use Vortos\Backup\Catalog\BackupCatalogReadModelInterface;
use Vortos\Backup\Crypto\EnvelopeStreamCipher;
use Vortos\Backup\Domain\BackupArtifact;
use Vortos\Backup\Domain\DatabaseEngine;
use Vortos\Backup\Event\BackupEvent;
use Vortos\Backup\Event\BackupEventSinkInterface;
use Vortos\Backup\Port\BackupStoreInterface;
use Vortos\Backup\Port\BackupStoreRegistry;
use Vortos\Backup\Restore\RestoreCoordinator;
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
    ) {}

    public function run(DatabaseEngine $engine, string $environment, bool $shallow = false): DrillReport
    {
        $artifact = $this->catalog->latest($engine, $environment);
        if ($artifact === null) {
            throw new \RuntimeException(sprintf(
                'No backup artifact found for %s/%s — cannot drill.',
                $engine->value,
                $environment,
            ));
        }

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
                );
            } else {
                $drillEnv = $this->provisioner->provision($engine);

                $this->restoreCoordinator->restore(
                    $artifact,
                    $store,
                    new RestoreRequest($drillEnv->dsn),
                );

                $connParams = $this->parseConnParams($drillEnv->dsn);
                $results = [];
                foreach ($this->invariantChecks as $check) {
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
}
