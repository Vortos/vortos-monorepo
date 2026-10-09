<?php

declare(strict_types=1);

namespace Vortos\ObjectStore\DependencyInjection;

/**
 * The client behind the WAL and backups buckets — bulk transfers, not request/response traffic.
 *
 * The primary client's request timeout is a latency budget for a web worker: it bounds how long one
 * stuck call can hold a thread, and it is tuned short on purpose. A transfer whose healthy duration
 * grows with the database cannot live under any fixed total, so this client has none. It fails on a
 * STALL instead — throughput below `stallMinBytesPerSecond` for `stallTimeoutSeconds` — which never
 * cuts a transfer that is still moving and still ends one that has stopped. Shared with nothing on
 * the request path, so tuning one can never break the other.
 */
final class ObjectStoreBulkTransferConfig
{
    private float $connectTimeout = 5.0;
    private int $stallTimeoutSeconds = 60;
    private int $stallMinBytesPerSecond = 1024;
    private int $maxRetries = 5;

    public function connectTimeout(float $seconds): static
    {
        $this->connectTimeout = $seconds;
        return $this;
    }

    public function stallTimeoutSeconds(int $seconds): static
    {
        $this->stallTimeoutSeconds = $seconds;
        return $this;
    }

    public function stallMinBytesPerSecond(int $bytes): static
    {
        $this->stallMinBytesPerSecond = $bytes;
        return $this;
    }

    /** SDK attempts per request — per PART on a ranged read or a multipart upload. */
    public function maxRetries(int $retries): static
    {
        $this->maxRetries = $retries;
        return $this;
    }

    /**
     * @internal
     *
     * @return array<string, int|float>
     */
    public function toArray(): array
    {
        return [
            'connect_timeout'            => $this->connectTimeout,
            'stall_timeout_seconds'      => $this->stallTimeoutSeconds,
            'stall_min_bytes_per_second' => $this->stallMinBytesPerSecond,
            'max_retries'                => $this->maxRetries,
        ];
    }
}
