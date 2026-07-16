<?php

declare(strict_types=1);

namespace Vortos\ObjectStore\Health;

use Aws\Exception\AwsException;
use Aws\S3\S3Client;
use Vortos\Foundation\Health\Attribute\AsHealthCheck;
use Vortos\Foundation\Health\Contract\HealthCheckInterface;
use Vortos\Foundation\Health\HealthResult;

/**
 * Readiness probe for the object store: a `HeadBucket` against the configured bucket.
 *
 * ## Cold-start resilience
 *
 * On a freshly (re)started worker/container the very first call pays for DNS, the TLS
 * handshake, and SDK client init — a one-shot probe run at that instant can transiently
 * fail (or arrive after a blue-green health-gate's per-attempt budget) even though the
 * store is perfectly healthy, false-negativing the gate and triggering a needless
 * rollback. So the check retries a small, bounded number of times with a short backoff:
 * a transient cold-connection blip is absorbed on the second attempt, while a genuinely
 * unreachable store still fails within the health runner's {@see AsHealthCheck::$timeoutMs}
 * budget. Steady-state (warm connection) probes succeed on the first attempt and pay no
 * extra latency.
 */
#[AsHealthCheck(critical: true, timeoutMs: 8000)]
final class S3ObjectStoreHealthCheck implements HealthCheckInterface
{
    public function __construct(
        private readonly S3Client $client,
        private readonly string $bucket,
        private readonly string $provider = 's3',
        private readonly int $coldStartAttempts = 3,
        private readonly int $coldStartBackoffMs = 200,
    ) {}

    public function name(): string
    {
        return 'object_store';
    }

    public function check(): HealthResult
    {
        $start    = hrtime(true);
        $attempts = max(1, $this->coldStartAttempts);
        $lastError = null;
        $lastCode  = 'object_store_unreachable';

        for ($attempt = 1; $attempt <= $attempts; $attempt++) {
            try {
                $this->client->headBucket(['Bucket' => $this->bucket]);

                return new HealthResult(
                    name: $this->name(),
                    healthy: true,
                    latencyMs: $this->ms($start),
                    error: $this->provider === 'r2' ? 'Cloudflare R2 bucket reachable.' : null,
                    errorCode: $this->provider === 'r2' ? 'object_store_r2_reachable' : null,
                    critical: true,
                );
            } catch (AwsException $e) {
                $lastError = $e->getAwsErrorMessage() ?? $e->getMessage();
            } catch (\Throwable $e) {
                $lastError = $e->getMessage();
            }

            // Back off before the next attempt (never after the last one) to give a cold
            // connection a moment to warm without busy-looping.
            if ($attempt < $attempts && $this->coldStartBackoffMs > 0) {
                usleep($this->coldStartBackoffMs * 1000);
            }
        }

        return new HealthResult(
            name: $this->name(),
            healthy: false,
            latencyMs: $this->ms($start),
            error: $lastError,
            errorCode: $lastCode,
        );
    }

    private function ms(int $start): float
    {
        return round((hrtime(true) - $start) / 1_000_000, 2);
    }
}
