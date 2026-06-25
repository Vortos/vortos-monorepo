<?php

declare(strict_types=1);

namespace Vortos\Alerts\RateLimit;

final class SlidingWindowOutboundRateLimiter implements OutboundRateLimiterInterface
{
    private const WINDOW_SECONDS = 3600;

    /** @var array<string, list<float>> */
    private array $tenantBuckets = [];

    /** @var list<float> */
    private array $globalBucket = [];

    /** @var array<string, list<float>> */
    private array $channelKindBuckets = [];

    public function __construct(
        private readonly OutboundRateLimitConfig $config,
    ) {}

    public function tryConsume(string $tenantId, string $channelKind): RateLimitDecision
    {
        $now = microtime(true);
        $cutoff = $now - self::WINDOW_SECONDS;

        if ($this->config->globalPerHour > 0) {
            $this->globalBucket = $this->evict($this->globalBucket, $cutoff);

            if (\count($this->globalBucket) >= $this->config->globalPerHour) {
                return RateLimitDecision::GlobalExhausted;
            }
        }

        if ($this->config->perTenantPerHour > 0) {
            $this->tenantBuckets[$tenantId] = $this->evict($this->tenantBuckets[$tenantId] ?? [], $cutoff);

            if (\count($this->tenantBuckets[$tenantId]) >= $this->config->perTenantPerHour) {
                return RateLimitDecision::TenantExhausted;
            }
        }

        $channelCap = $this->config->perChannelKindPerHour[$channelKind] ?? 0;
        if ($channelCap > 0) {
            $key = $tenantId . '|' . $channelKind;
            $this->channelKindBuckets[$key] = $this->evict($this->channelKindBuckets[$key] ?? [], $cutoff);

            if (\count($this->channelKindBuckets[$key]) >= $channelCap) {
                return RateLimitDecision::TenantExhausted;
            }
        }

        $this->globalBucket[] = $now;
        $this->tenantBuckets[$tenantId][] = $now;

        if ($channelCap > 0) {
            $this->channelKindBuckets[$tenantId . '|' . $channelKind][] = $now;
        }

        return RateLimitDecision::Allowed;
    }

    /**
     * @param list<float> $bucket
     * @return list<float>
     */
    private function evict(array $bucket, float $cutoff): array
    {
        return array_values(array_filter($bucket, static fn (float $ts): bool => $ts > $cutoff));
    }
}
