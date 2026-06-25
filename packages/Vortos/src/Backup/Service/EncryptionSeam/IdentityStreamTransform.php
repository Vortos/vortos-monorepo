<?php

declare(strict_types=1);

namespace Vortos\Backup\Service\EncryptionSeam;

/**
 * The no-op transform: passes the source stream through unchanged.
 *
 * The default in Block 19. Block 20 replaces the {@see StreamTransformInterface}
 * alias with an envelope-encryption transform; nothing else changes.
 */
final class IdentityStreamTransform implements StreamTransformInterface
{
    /**
     * @param resource $source
     *
     * @return resource
     */
    public function transform(mixed $source): mixed
    {
        return $source;
    }

    public function name(): string
    {
        return 'identity';
    }
}
