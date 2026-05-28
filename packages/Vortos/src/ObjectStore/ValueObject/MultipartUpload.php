<?php

declare(strict_types=1);

namespace Vortos\ObjectStore\ValueObject;

final readonly class MultipartUpload
{
    public function __construct(
        public string $key,
        public string $uploadId,
        public \DateTimeImmutable $initiatedAt,
    ) {}
}
