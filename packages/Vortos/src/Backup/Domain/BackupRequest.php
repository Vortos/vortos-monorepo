<?php

declare(strict_types=1);

namespace Vortos\Backup\Domain;

use InvalidArgumentException;

/**
 * A request to take one backup — the pure input to a {@see \Vortos\Backup\Port\BackupTargetInterface}.
 *
 * `fromReplica` / `consistentSnapshot` are *requests* honoured only when the target's
 * capability descriptor supports them; a target that cannot honour a hard requirement
 * raises {@see \Vortos\OpsKit\Driver\Exception\UnsupportedCapabilityException} rather
 * than silently degrading.
 */
final readonly class BackupRequest
{
    public function __construct(
        public DatabaseEngine $engine,
        public BackupKind $kind,
        public string $environment,
        public bool $fromReplica = true,
        public bool $consistentSnapshot = true,
        public CompressionCodec $codec = CompressionCodec::Gzip,
    ) {
        if ($environment === '') {
            throw new InvalidArgumentException('Backup environment must be non-empty.');
        }
    }

    public function withKind(BackupKind $kind): self
    {
        return new self($this->engine, $kind, $this->environment, $this->fromReplica, $this->consistentSnapshot, $this->codec);
    }
}
