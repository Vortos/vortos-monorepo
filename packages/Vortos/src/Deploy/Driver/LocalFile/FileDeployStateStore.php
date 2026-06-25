<?php

declare(strict_types=1);

namespace Vortos\Deploy\Driver\LocalFile;

use Vortos\Deploy\Cutover\RateLimitStateStoreInterface;
use Vortos\Deploy\PullAgent\FreshnessSnapshot;
use Vortos\Deploy\PullAgent\ManifestFreshnessStoreInterface;
use Vortos\Deploy\State\ContractSoakLedgerInterface;
use Vortos\Deploy\State\ContractSoakRecord;
use Vortos\Deploy\State\CurrentRelease;
use Vortos\Deploy\State\CurrentReleaseStoreInterface;
use Vortos\Deploy\State\DeployRun;
use Vortos\Deploy\State\DeployStateStoreInterface;
use Vortos\Deploy\State\DeployStatus;
use Vortos\Deploy\State\StepOutcome;
use Vortos\OpsKit\Attribute\AsDriver;
use Vortos\OpsKit\Driver\Capability\CapabilityDescriptor;

#[AsDriver('file')]
final class FileDeployStateStore implements
    DeployStateStoreInterface,
    CurrentReleaseStoreInterface,
    ContractSoakLedgerInterface,
    ManifestFreshnessStoreInterface,
    RateLimitStateStoreInterface
{
    public function __construct(
        private readonly string $stateDir,
    ) {
        if (!is_dir($this->stateDir) && !mkdir($this->stateDir, 0755, true) && !is_dir($this->stateDir)) {
            throw new \RuntimeException(sprintf('Cannot create state directory: %s', $this->stateDir));
        }
    }

    public function capabilities(): CapabilityDescriptor
    {
        return CapabilityDescriptor::create([
            'durable' => true,
            'concurrent_safe' => false,
            'queryable' => false,
        ]);
    }

    public function begin(DeployRun $run): void
    {
        $run->status = DeployStatus::Running;
        $this->persist($run);
    }

    public function checkpoint(string $runId, int $stepIndex, StepOutcome $outcome): void
    {
        $run = $this->findByRunId($runId);
        if ($run === null) {
            throw new \RuntimeException(sprintf('Deploy run not found: %s', $runId));
        }

        $run->addOutcome($outcome);
        $this->persist($run);
    }

    public function find(string $env, string $planHash): ?DeployRun
    {
        $path = $this->filePath($env, $planHash);

        if (!file_exists($path)) {
            return null;
        }

        $handle = fopen($path, 'r');
        if ($handle === false) {
            return null;
        }

        try {
            flock($handle, \LOCK_SH);
            $content = stream_get_contents($handle);

            if ($content === false || $content === '') {
                return null;
            }

            $data = json_decode($content, true, 512, \JSON_THROW_ON_ERROR);

            return DeployRun::fromArray($data);
        } finally {
            flock($handle, \LOCK_UN);
            fclose($handle);
        }
    }

    public function complete(string $runId): void
    {
        $run = $this->findByRunId($runId);
        if ($run === null) {
            throw new \RuntimeException(sprintf('Deploy run not found: %s', $runId));
        }

        $run->status = DeployStatus::Completed;
        $this->persist($run);
    }

    public function fail(string $runId, string $reason): void
    {
        $run = $this->findByRunId($runId);
        if ($run === null) {
            throw new \RuntimeException(sprintf('Deploy run not found: %s', $runId));
        }

        $run->status = DeployStatus::Failed;
        $this->persist($run);
    }

    private function persist(DeployRun $run): void
    {
        $path = $this->filePath($run->env, $run->planHash);
        $dir = \dirname($path);

        if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
            throw new \RuntimeException(sprintf('Cannot create state directory: %s', $dir));
        }

        $tmpPath = $path . '.tmp.' . bin2hex(random_bytes(4));
        $json = json_encode($run->toArray(), \JSON_THROW_ON_ERROR | \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES);

        $handle = fopen($tmpPath, 'c');
        if ($handle === false) {
            throw new \RuntimeException(sprintf('Cannot open temp file for writing: %s', $tmpPath));
        }

        try {
            flock($handle, \LOCK_EX);
            ftruncate($handle, 0);
            fwrite($handle, $json);
            fflush($handle);
            flock($handle, \LOCK_UN);
        } finally {
            fclose($handle);
        }

        rename($tmpPath, $path);
    }

    private function findByRunId(string $runId): ?DeployRun
    {
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->stateDir, \FilesystemIterator::SKIP_DOTS),
        );

        foreach ($iterator as $file) {
            if ($file->getExtension() !== 'json') {
                continue;
            }

            $content = file_get_contents($file->getPathname());
            if ($content === false) {
                continue;
            }

            try {
                $data = json_decode($content, true, 512, \JSON_THROW_ON_ERROR);
            } catch (\JsonException) {
                continue;
            }

            if (($data['run_id'] ?? null) === $runId) {
                return DeployRun::fromArray($data);
            }
        }

        return null;
    }

    public function recordCurrentRelease(CurrentRelease $release): void
    {
        $path = $this->currentReleasePath($release->env);
        $dir = \dirname($path);

        if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
            throw new \RuntimeException(sprintf('Cannot create state directory: %s', $dir));
        }

        $lockPath = $path . '.lock';
        $lockHandle = fopen($lockPath, 'c');
        if ($lockHandle === false) {
            throw new \RuntimeException(sprintf('Cannot open lock file: %s', $lockPath));
        }

        try {
            flock($lockHandle, \LOCK_EX);

            if (file_exists($path)) {
                $existing = file_get_contents($path);
                if ($existing !== false && $existing !== '') {
                    $stored = CurrentRelease::fromArray(json_decode($existing, true, 512, \JSON_THROW_ON_ERROR));
                    if ($release->generation <= $stored->generation) {
                        return;
                    }
                }
            }

            $tmpPath = $path . '.tmp.' . bin2hex(random_bytes(4));
            $json = json_encode($release->toArray(), \JSON_THROW_ON_ERROR | \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES);
            file_put_contents($tmpPath, $json);
            rename($tmpPath, $path);
        } finally {
            flock($lockHandle, \LOCK_UN);
            fclose($lockHandle);
        }
    }

    public function currentRelease(string $env): ?CurrentRelease
    {
        $path = $this->currentReleasePath($env);

        if (!file_exists($path)) {
            return null;
        }

        $content = file_get_contents($path);
        if ($content === false || $content === '') {
            return null;
        }

        return CurrentRelease::fromArray(json_decode($content, true, 512, \JSON_THROW_ON_ERROR));
    }

    private function currentReleasePath(string $env): string
    {
        return sprintf('%s/current-release/%s.json', $this->stateDir, $env);
    }

    public function recordContractSoakObservation(string $env, string $migrationId, int $currentGeneration): ContractSoakRecord
    {
        $path = $this->contractSoakPath($env, $migrationId);
        $dir = \dirname($path);

        if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
            throw new \RuntimeException(sprintf('Cannot create state directory: %s', $dir));
        }

        $lockPath = $path . '.lock';
        $lockHandle = fopen($lockPath, 'c');
        if ($lockHandle === false) {
            throw new \RuntimeException(sprintf('Cannot open lock file: %s', $lockPath));
        }

        try {
            flock($lockHandle, \LOCK_EX);

            if (file_exists($path)) {
                $existing = file_get_contents($path);
                if ($existing !== false && $existing !== '') {
                    return ContractSoakRecord::fromArray(json_decode($existing, true, 512, \JSON_THROW_ON_ERROR));
                }
            }

            $record = new ContractSoakRecord($migrationId, new \DateTimeImmutable(), $currentGeneration);

            $tmpPath = $path . '.tmp.' . bin2hex(random_bytes(4));
            $json = json_encode($record->toArray(), \JSON_THROW_ON_ERROR | \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES);
            file_put_contents($tmpPath, $json);
            rename($tmpPath, $path);

            return $record;
        } finally {
            flock($lockHandle, \LOCK_UN);
            fclose($lockHandle);
        }
    }

    public function contractSoakRecord(string $env, string $migrationId): ?ContractSoakRecord
    {
        $path = $this->contractSoakPath($env, $migrationId);

        if (!file_exists($path)) {
            return null;
        }

        $content = file_get_contents($path);
        if ($content === false || $content === '') {
            return null;
        }

        return ContractSoakRecord::fromArray(json_decode($content, true, 512, \JSON_THROW_ON_ERROR));
    }

    private function contractSoakPath(string $env, string $migrationId): string
    {
        return sprintf('%s/contract-soak/%s/%s.json', $this->stateDir, $env, hash('sha256', $migrationId));
    }

    private function filePath(string $env, string $planHash): string
    {
        return sprintf('%s/%s/%s.json', $this->stateDir, $env, $planHash);
    }

    public function loadFreshnessState(string $env): FreshnessSnapshot
    {
        $path = $this->freshnessPath($env);

        if (!file_exists($path)) {
            return FreshnessSnapshot::empty($env);
        }

        $content = file_get_contents($path);
        if ($content === false || $content === '') {
            return FreshnessSnapshot::empty($env);
        }

        return FreshnessSnapshot::fromArray(json_decode($content, true, 512, \JSON_THROW_ON_ERROR));
    }

    public function saveFreshnessState(string $env, FreshnessSnapshot $snapshot): void
    {
        $path = $this->freshnessPath($env);
        $dir = \dirname($path);

        if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
            throw new \RuntimeException(sprintf('Cannot create state directory: %s', $dir));
        }

        $lockPath = $path . '.lock';
        $lockHandle = fopen($lockPath, 'c');
        if ($lockHandle === false) {
            throw new \RuntimeException(sprintf('Cannot open lock file: %s', $lockPath));
        }

        try {
            flock($lockHandle, \LOCK_EX);

            if (file_exists($path)) {
                $existing = file_get_contents($path);
                if ($existing !== false && $existing !== '') {
                    $stored = FreshnessSnapshot::fromArray(json_decode($existing, true, 512, \JSON_THROW_ON_ERROR));
                    if ($snapshot->lastAppliedVersion < $stored->lastAppliedVersion) {
                        return;
                    }
                }
            }

            $tmpPath = $path . '.tmp.' . bin2hex(random_bytes(4));
            $json = json_encode($snapshot->toArray(), \JSON_THROW_ON_ERROR | \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES);
            file_put_contents($tmpPath, $json);
            rename($tmpPath, $path);
        } finally {
            flock($lockHandle, \LOCK_UN);
            fclose($lockHandle);
        }
    }

    private function freshnessPath(string $env): string
    {
        return sprintf('%s/pull-agent-freshness/%s.json', $this->stateDir, $env);
    }

    public function loadLastReloadTimestamp(string $env): ?float
    {
        $path = $this->rateLimitPath($env);

        if (!file_exists($path)) {
            return null;
        }

        $content = file_get_contents($path);
        if ($content === false || $content === '') {
            return null;
        }

        $data = json_decode($content, true, 512, \JSON_THROW_ON_ERROR);

        return isset($data['last_reload_at']) ? (float) $data['last_reload_at'] : null;
    }

    public function saveLastReloadTimestamp(string $env, float $timestamp): void
    {
        $path = $this->rateLimitPath($env);
        $dir = \dirname($path);

        if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
            throw new \RuntimeException(sprintf('Cannot create state directory: %s', $dir));
        }

        $tmpPath = $path . '.tmp.' . bin2hex(random_bytes(4));
        $json = json_encode(['last_reload_at' => $timestamp], \JSON_THROW_ON_ERROR);
        file_put_contents($tmpPath, $json);
        rename($tmpPath, $path);
    }

    private function rateLimitPath(string $env): string
    {
        return sprintf('%s/rate-limit/%s.json', $this->stateDir, $env);
    }
}
