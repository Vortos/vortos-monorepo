<?php

declare(strict_types=1);

namespace Vortos\Auth\Session;

use Psr\Log\LoggerInterface;
use Vortos\Auth\Session\Contract\SessionStoreInterface;
use Vortos\Metrics\Telemetry\FrameworkTelemetry;
use Vortos\Observability\Config\ObservabilityModule;
use Vortos\Observability\Telemetry\FrameworkMetric;
use Vortos\Observability\Telemetry\FrameworkMetricLabels;
use Vortos\Observability\Telemetry\MetricLabel;
use Vortos\Observability\Telemetry\MetricLabelValue;

/**
 * Decides whether an access token's session (sid) is still live, cheaply and resiliently.
 *
 * The naive check — one session-store lookup per authenticated request — is correct but, at
 * scale, adds a Redis round-trip to every request and turns a Redis blip into a site-wide
 * outage. This guard wraps the lookup with two production concerns:
 *
 *  1. **Positive cache.** A "still live" answer is cached in-process for a short TTL, so a busy
 *     session costs at most one lookup per window instead of one per request. In FrankenPHP
 *     worker mode the cache persists across requests within a worker; under classic FPM it is
 *     per-request (harmless). Only *positive* results are cached — a revoked session is
 *     authoritative and returned immediately, so revocation still takes effect within the
 *     (small) cache TTL rather than after the whole access-token lifetime.
 *
 *  2. **Circuit breaker.** Consecutive store failures open the breaker, after which the guard
 *     fails open without touching the store until a reset window elapses — a Redis outage can
 *     never lock everyone out, and we stop hammering a struggling store. Store failures and the
 *     resulting fail-open are counted as security metrics so the blindness is observable.
 *
 * Revocation latency is therefore bounded by the positive-cache TTL (default 5s), a deliberate
 * trade of instant-but-per-request for near-instant-but-cheap. Set the TTL to 0 to disable the
 * cache and get strict per-request checks.
 */
final class SessionLivenessGuard
{
    /** Safety cap on the in-process cache so a long-lived worker can't grow it unbounded. */
    private const CACHE_MAX_ENTRIES = 20_000;

    /** @var array<string, int> "userId|sid" => unix expiry */
    private array $liveCache = [];

    private int $consecutiveFailures = 0;
    private int $breakerOpenedAt = 0;

    public function __construct(
        private readonly SessionStoreInterface $store,
        private readonly int $positiveCacheTtlSeconds = 5,
        private readonly int $breakerFailureThreshold = 5,
        private readonly int $breakerResetSeconds = 30,
        private readonly ?FrameworkTelemetry $telemetry = null,
        private readonly ?LoggerInterface $logger = null,
    ) {}

    /**
     * True if the session is live, or if liveness cannot be determined (fail-open).
     */
    public function isLive(string $userId, string $sid): bool
    {
        $now = time();
        $key = $userId . '|' . $sid;

        if (($this->liveCache[$key] ?? 0) > $now) {
            return true; // positive-cache hit — no store call
        }

        if (!$this->breakerAllowsProbe($now)) {
            return true; // breaker open — fail open, don't touch the store
        }

        try {
            $live = $this->store->hasSession($userId, $sid);
            $this->recordSuccess();
        } catch (\Throwable $e) {
            $this->recordFailure($now);
            $this->emit('auth.session.liveness_store_unavailable');
            $this->logger?->warning('Session-liveness check failed; failing open.', [
                'user_id'   => $userId,
                'exception' => $e->getMessage(),
            ]);
            return true;
        }

        if ($live && $this->positiveCacheTtlSeconds > 0) {
            $this->cachePositive($key, $now);
        }

        return $live;
    }

    private function cachePositive(string $key, int $now): void
    {
        if (count($this->liveCache) >= self::CACHE_MAX_ENTRIES) {
            $this->pruneExpired($now);
            if (count($this->liveCache) >= self::CACHE_MAX_ENTRIES) {
                // Still full of live entries — drop everything rather than grow without bound.
                $this->liveCache = [];
            }
        }

        $this->liveCache[$key] = $now + $this->positiveCacheTtlSeconds;
    }

    private function pruneExpired(int $now): void
    {
        foreach ($this->liveCache as $k => $expiry) {
            if ($expiry <= $now) {
                unset($this->liveCache[$k]);
            }
        }
    }

    // ── Inline circuit breaker ────────────────────────────────────────────────

    private function breakerAllowsProbe(int $now): bool
    {
        if ($this->consecutiveFailures < $this->breakerFailureThreshold) {
            return true; // closed
        }

        // Open — allow a single probe once the reset window has elapsed (half-open).
        return ($now - $this->breakerOpenedAt) >= $this->breakerResetSeconds;
    }

    private function recordSuccess(): void
    {
        $this->consecutiveFailures = 0;
        $this->breakerOpenedAt = 0;
    }

    private function recordFailure(int $now): void
    {
        ++$this->consecutiveFailures;
        if ($this->consecutiveFailures === $this->breakerFailureThreshold) {
            $this->breakerOpenedAt = $now;
        } elseif ($this->consecutiveFailures > $this->breakerFailureThreshold) {
            // A probe failed — re-arm the reset window.
            $this->breakerOpenedAt = $now;
        }
    }

    private function emit(string $event): void
    {
        $this->telemetry?->increment(
            ObservabilityModule::Security,
            FrameworkMetric::SecurityEventsTotal,
            FrameworkMetricLabels::of(MetricLabelValue::of(MetricLabel::Event, $event)),
        );
    }
}
