<?php

declare(strict_types=1);

namespace Vortos\ObjectStore\Exception;

final class PresignedUrlPolicyException extends ObjectStoreException
{
    public static function ttlTooLong(int $requestedSeconds, int $maxSeconds): self
    {
        return new self(sprintf(
            'Presigned URL TTL of %d seconds exceeds the configured maximum of %d seconds.',
            $requestedSeconds,
            $maxSeconds,
        ));
    }

    public static function uploadTooLarge(int $requestedBytes, int $maxBytes): self
    {
        return new self(sprintf(
            'Direct upload size limit of %d bytes exceeds the configured maximum of %d bytes.',
            $requestedBytes,
            $maxBytes,
        ));
    }
}
