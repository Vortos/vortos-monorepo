<?php

declare(strict_types=1);

namespace Vortos\ObjectStore\Contract;

use Vortos\ObjectStore\ValueObject\GetObjectOptions;
use Vortos\ObjectStore\ValueObject\ObjectKey;
use Vortos\ObjectStore\ValueObject\PresignedPostPolicy;
use Vortos\ObjectStore\ValueObject\PresignedUploadUrl;
use Vortos\ObjectStore\ValueObject\PresignedUrl;
use Vortos\ObjectStore\ValueObject\TemporaryUploadUrlOptions;

interface PresignedUrlGeneratorInterface
{
    public function temporaryDownloadUrl(
        ObjectKey|string $key,
        \DateTimeImmutable $expiresAt,
        ?GetObjectOptions $options = null,
    ): PresignedUrl;

    public function temporaryUploadUrl(
        ObjectKey|string $key,
        TemporaryUploadUrlOptions $options,
    ): PresignedUploadUrl;

    public function temporaryPostUpload(
        ObjectKey|string $key,
        TemporaryUploadUrlOptions $options,
    ): PresignedPostPolicy;
}
