<?php

declare(strict_types=1);

namespace Vortos\ObjectStore\ValueObject;

final readonly class ServerSideMultipartUploadOptions
{
    /** @param null|callable(int $partNumber, int $bytesUploaded): void $onPartUploaded */
    public function __construct(
        public ?int $thresholdBytes = null,
        public ?int $partSizeBytes = null,
        public ?int $maxObjectSizeBytes = null,
        public ?int $maxInlineBodyBytes = null,
        public ?int $maxAttempts = null,
        public ?int $concurrency = null,
        public ?int $backoffBaseMilliseconds = null,
        public ?int $backoffCapMilliseconds = null,
        public ?string $checksumAlgorithm = null,
        public mixed $onPartUploaded = null,
    ) {
        if ($this->onPartUploaded !== null && !is_callable($this->onPartUploaded)) {
            throw new \InvalidArgumentException('Multipart upload progress callback must be callable.');
        }
    }
}
