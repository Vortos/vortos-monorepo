<?php

declare(strict_types=1);

namespace Vortos\Backup\Tests\Support;

use RuntimeException;
use Vortos\Backup\Domain\BackupRequest;
use Vortos\Backup\Domain\CompressionCodec;
use Vortos\Backup\Domain\DatabaseEngine;
use Vortos\Backup\Domain\SourceRef;
use Vortos\Backup\Port\BackupStream;
use Vortos\Backup\Port\BackupTargetInterface;
use Vortos\Backup\Service\Process\ProcessGuard;
use Vortos\OpsKit\Driver\Capability\CapabilityDescriptor;

/** @internal a controllable backup target for runner tests */
class FakeBackupTarget implements BackupTargetInterface
{
    public function __construct(
        private readonly DatabaseEngine $engine = DatabaseEngine::Postgres,
        private readonly string $payload = "PGDMP\x00fake-dump-body",
        private readonly CompressionCodec $codec = CompressionCodec::None,
        private readonly bool $throwOnDump = false,
        private readonly ?ProcessGuard $guard = null,
    ) {}

    public function engine(): DatabaseEngine
    {
        return $this->engine;
    }

    public function capabilities(): CapabilityDescriptor
    {
        return CapabilityDescriptor::create(['streaming' => true]);
    }

    public function dump(BackupRequest $request): BackupStream
    {
        if ($this->throwOnDump) {
            throw new RuntimeException('dump exploded');
        }

        $stream = fopen('php://temp', 'r+b');
        fwrite($stream, $this->payload);
        rewind($stream);

        return new BackupStream($stream, $this->engine, $request->kind, $this->codec, SourceRef::none(), $this->guard);
    }
}
