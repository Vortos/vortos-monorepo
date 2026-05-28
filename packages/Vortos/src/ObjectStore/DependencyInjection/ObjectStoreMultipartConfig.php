<?php

declare(strict_types=1);

namespace Vortos\ObjectStore\DependencyInjection;

final class ObjectStoreMultipartConfig
{
    private int $thresholdBytes = 104857600;
    private int $partSizeBytes = 16777216;
    private bool $abortOnFailure = true;
    private int $maxObjectSizeBytes = 5497558138880;
    private int $maxInlineBodyBytes = 16777216;
    private int $maxAttempts = 3;
    private int $concurrency = 4;
    private int $backoffBaseMilliseconds = 100;
    private int $backoffCapMilliseconds = 2000;
    private ?string $checksumAlgorithm = null;

    public function thresholdBytes(int $bytes): static
    {
        $this->thresholdBytes = $bytes;
        return $this;
    }

    public function partSizeBytes(int $bytes): static
    {
        $this->partSizeBytes = $bytes;
        return $this;
    }

    public function abortOnFailure(bool $enabled): static
    {
        $this->abortOnFailure = $enabled;
        return $this;
    }

    public function maxObjectSizeBytes(int $bytes): static
    {
        $this->maxObjectSizeBytes = $bytes;
        return $this;
    }

    public function maxInlineBodyBytes(int $bytes): static
    {
        $this->maxInlineBodyBytes = $bytes;
        return $this;
    }

    public function maxAttempts(int $attempts): static
    {
        $this->maxAttempts = $attempts;
        return $this;
    }

    public function concurrency(int $concurrency): static
    {
        $this->concurrency = $concurrency;
        return $this;
    }

    public function backoffBaseMilliseconds(int $milliseconds): static
    {
        $this->backoffBaseMilliseconds = $milliseconds;
        return $this;
    }

    public function backoffCapMilliseconds(int $milliseconds): static
    {
        $this->backoffCapMilliseconds = $milliseconds;
        return $this;
    }

    public function checksumAlgorithm(?string $algorithm): static
    {
        $this->checksumAlgorithm = $algorithm;
        return $this;
    }

    /** @internal */
    public function toArray(): array
    {
        return [
            'threshold_bytes'  => $this->thresholdBytes,
            'part_size_bytes'  => $this->partSizeBytes,
            'abort_on_failure' => $this->abortOnFailure,
            'max_object_size_bytes' => $this->maxObjectSizeBytes,
            'max_inline_body_bytes' => $this->maxInlineBodyBytes,
            'max_attempts' => $this->maxAttempts,
            'concurrency' => $this->concurrency,
            'backoff_base_milliseconds' => $this->backoffBaseMilliseconds,
            'backoff_cap_milliseconds' => $this->backoffCapMilliseconds,
            'checksum_algorithm' => $this->checksumAlgorithm,
        ];
    }
}
