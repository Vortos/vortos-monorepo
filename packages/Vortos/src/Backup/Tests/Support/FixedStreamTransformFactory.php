<?php

declare(strict_types=1);

namespace Vortos\Backup\Tests\Support;

use Vortos\Backup\Domain\BackupKind;
use Vortos\Backup\Domain\CompressionCodec;
use Vortos\Backup\Domain\DatabaseEngine;
use Vortos\Backup\Service\EncryptionSeam\StreamTransformFactoryInterface;
use Vortos\Backup\Service\EncryptionSeam\StreamTransformInterface;

/** Hands the runner one pre-built transform, so tests can assert on the instance they supplied. */
final class FixedStreamTransformFactory implements StreamTransformFactoryInterface
{
    public function __construct(private readonly StreamTransformInterface $transform) {}

    public function forBackup(
        DatabaseEngine $engine,
        BackupKind $kind,
        CompressionCodec $codec,
    ): StreamTransformInterface {
        return $this->transform;
    }
}
