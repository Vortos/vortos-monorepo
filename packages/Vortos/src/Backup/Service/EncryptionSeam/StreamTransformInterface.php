<?php

declare(strict_types=1);

namespace Vortos\Backup\Service\EncryptionSeam;

/**
 * The single insertion point for transforming a backup stream on its way to the store.
 *
 * Block 19 ships {@see IdentityStreamTransform} (a no-op). Block 20 registers an
 * envelope-encryption transform (DEK wrapped by an off-host KEK via `vortos-secrets`)
 * here — so at-rest encryption is added with **zero changes** to {@see \Vortos\Backup\Service\BackupRunner}.
 *
 * Implementations wrap the source resource and return a new readable resource; they
 * must stream (bounded memory), never buffer the whole artifact.
 */
interface StreamTransformInterface
{
    /**
     * @param resource $source
     *
     * @return resource a readable stream of the transformed bytes
     */
    public function transform(mixed $source): mixed;

    /** A stable identifier recorded for diagnostics (e.g. `identity`, `age-envelope`). */
    public function name(): string;
}
