<?php

declare(strict_types=1);

namespace Vortos\Logger\DependencyInjection;

use Monolog\Level;
use Vortos\Logger\Config\BufferPolicy;
use Vortos\Logger\Config\RotationPolicy;
use Vortos\Logger\Config\SinkDefinition;
use Vortos\Logger\Config\SinkDestination;

/**
 * Fluent builder for a single sink (log destination).
 *
 * Obtained via VortosLoggingConfig::sink($id). Build a SinkDefinition by
 * calling toFile()/toStream()/toSyslog()/customHandler() plus optional
 * level()/writeThrough()/batched()/rotation()/sample()/hashChain().
 */
final class SinkBuilder
{
    private SinkDestination $destination = SinkDestination::File;
    private ?string $path = null;
    private Level $level = Level::Debug;
    private BufferPolicy $bufferPolicy = BufferPolicy::Batched;
    private RotationPolicy $rotation;
    private ?int $sampleFactor = null;
    private bool $hashChain = false;
    private int $flushIntervalSeconds = 2;
    private ?string $customHandlerServiceId = null;
    private bool $complianceRiskAcknowledged = false;
    private bool $destinationSet = false;

    public function __construct(private readonly string $id)
    {
        $this->rotation = new RotationPolicy();
    }

    /** Write to a file under the log directory (relative) or an absolute path. */
    public function toFile(string $path): static
    {
        $this->destination = SinkDestination::File;
        $this->path = $path;
        $this->destinationSet = true;
        return $this;
    }

    /** Write to a PHP stream URI, e.g. 'php://stderr'. */
    public function toStream(string $stream): static
    {
        $this->destination = SinkDestination::Stream;
        $this->path = $stream;
        $this->rotation = RotationPolicy::disabled();
        $this->destinationSet = true;
        return $this;
    }

    /** Write to syslog under the given ident. */
    public function toSyslog(string $ident): static
    {
        $this->destination = SinkDestination::Syslog;
        $this->path = $ident;
        $this->rotation = RotationPolicy::disabled();
        $this->destinationSet = true;
        return $this;
    }

    /**
     * Route to a custom Monolog handler registered under $serviceId.
     *
     * Use this for OTLP collectors, Kafka topics, vendor SDKs, etc. — the
     * framework only needs to know the handler exists and is wired with
     * `addTag('vortos.logger.handler')`.
     */
    public function customHandler(string $serviceId): static
    {
        $this->destination = SinkDestination::Custom;
        $this->customHandlerServiceId = $serviceId;
        $this->rotation = RotationPolicy::disabled();
        $this->destinationSet = true;
        return $this;
    }

    /**
     * If no destination was explicitly configured, apply the env-driven
     * default: a rotating file under var/log in dev, php://stderr in prod.
     *
     * @internal Called by VortosLoggingConfig::resolve().
     */
    public function applyDefaultDestinationIfUnset(string $env, string $id): void
    {
        if ($this->destinationSet) {
            return;
        }

        if ($env === 'dev') {
            $this->toFile($id . '.log');
        } else {
            $this->toStream('php://stderr');
        }
    }

    public function level(Level $level): static
    {
        $this->level = $level;
        return $this;
    }

    /**
     * Every record is written immediately — no buffering, no loss on crash.
     * Default for Security/Audit channels' sinks.
     */
    public function writeThrough(): static
    {
        $this->bufferPolicy = BufferPolicy::WriteThrough;
        return $this;
    }

    /** Buffer records and flush every $flushIntervalSeconds (default 2). */
    public function batched(int $flushIntervalSeconds = 2): static
    {
        $this->bufferPolicy = BufferPolicy::Batched;
        $this->flushIntervalSeconds = $flushIntervalSeconds;
        return $this;
    }

    /**
     * Rotation/retention for file sinks. Enabled by default
     * (daily rotation, 14 files, 30 days, 1024MB total, gzip).
     */
    public function rotation(
        bool $enabled = true,
        int $maxFiles = 14,
        int $maxAgeDays = 30,
        int $maxTotalSizeMb = 1024,
        bool $compress = true,
    ): static {
        $this->rotation = $enabled
            ? new RotationPolicy($enabled, $maxFiles, $maxAgeDays, $maxTotalSizeMb, $compress)
            : RotationPolicy::disabled();
        return $this;
    }

    /**
     * Only handle ~1/$factor of records. Use for high-volume, low-value
     * channels (e.g. Cache). Records at Error+ should not be sampled —
     * configure a separate unsampled sink for those if needed.
     */
    public function sample(int $factor): static
    {
        $this->sampleFactor = max(1, $factor);
        return $this;
    }

    /**
     * Append a tamper-evident hash chain (prev_hash/record_hash) to every
     * record on this sink. Intended for the Audit channel.
     */
    public function hashChain(bool $enabled = true): static
    {
        $this->hashChain = $enabled;
        return $this;
    }

    /**
     * Explicitly accept reducing this sink's retention below the compliance
     * floor (Audit sinks only). Required to bypass InvalidLoggingConfigException.
     */
    public function acknowledgeComplianceRisk(): static
    {
        $this->complianceRiskAcknowledged = true;
        return $this;
    }

    public function complianceRiskAcknowledged(): bool
    {
        return $this->complianceRiskAcknowledged;
    }

    public function build(): SinkDefinition
    {
        return new SinkDefinition(
            id: $this->id,
            destination: $this->destination,
            path: $this->path,
            level: $this->level,
            bufferPolicy: $this->bufferPolicy,
            rotation: $this->rotation,
            sampleFactor: $this->sampleFactor,
            hashChain: $this->hashChain,
            flushIntervalSeconds: $this->flushIntervalSeconds,
            customHandlerServiceId: $this->customHandlerServiceId,
        );
    }
}
