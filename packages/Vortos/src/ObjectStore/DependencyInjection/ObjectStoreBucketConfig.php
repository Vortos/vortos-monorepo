<?php

declare(strict_types=1);

namespace Vortos\ObjectStore\DependencyInjection;

final class ObjectStoreBucketConfig
{
    private string $name = '';
    private string $walName = '';
    private string $keyPrefix = '';
    private string $temporaryKeyPrefix = 'tmp';
    private ?string $publicBaseUrl = null;
    private int $maxUploadSizeBytes = 5368709120;
    private int $defaultPresignTtlSeconds = 900;
    private int $maxPresignTtlSeconds = 3600;
    private int $orphanTtlSeconds = 86400;

    public function name(string $name): static
    {
        $this->name = $name;
        return $this;
    }

    /**
     * A second bucket for WAL segments, when they must not share the primary one.
     *
     * Object Lock is a bucket-level setting: right for restore points, wrong for WAL, where a segment
     * lands every few minutes and pruning the old ones is the entire point. Empty keeps WAL with
     * everything else.
     */
    public function walName(string $name): static
    {
        $this->walName = $name;
        return $this;
    }

    public function keyPrefix(string $prefix): static
    {
        $this->keyPrefix = trim($prefix, '/');
        return $this;
    }

    public function temporaryKeyPrefix(string $prefix): static
    {
        $this->temporaryKeyPrefix = trim($prefix, '/');
        return $this;
    }

    public function publicBaseUrl(?string $url): static
    {
        $this->publicBaseUrl = $url;
        return $this;
    }

    public function maxUploadSizeBytes(int $bytes): static
    {
        $this->maxUploadSizeBytes = $bytes;
        return $this;
    }

    public function defaultPresignTtlSeconds(int $seconds): static
    {
        $this->defaultPresignTtlSeconds = $seconds;
        return $this;
    }

    public function maxPresignTtlSeconds(int $seconds): static
    {
        $this->maxPresignTtlSeconds = $seconds;
        return $this;
    }

    public function orphanTtlSeconds(int $seconds): static
    {
        $this->orphanTtlSeconds = $seconds;
        return $this;
    }

    /** @internal */
    public function toArray(): array
    {
        return [
            'name'                        => $this->name,
            'wal_name'                    => $this->walName,
            'key_prefix'                  => $this->keyPrefix,
            'temporary_key_prefix'        => $this->temporaryKeyPrefix,
            'public_base_url'             => $this->publicBaseUrl,
            'max_upload_size_bytes'       => $this->maxUploadSizeBytes,
            'default_presign_ttl_seconds' => $this->defaultPresignTtlSeconds,
            'max_presign_ttl_seconds'     => $this->maxPresignTtlSeconds,
            'orphan_ttl_seconds'          => $this->orphanTtlSeconds,
        ];
    }
}
