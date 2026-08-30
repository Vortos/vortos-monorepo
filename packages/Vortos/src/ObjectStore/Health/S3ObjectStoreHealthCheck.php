<?php

declare(strict_types=1);

namespace Vortos\ObjectStore\Health;

use Aws\Exception\AwsException;
use Aws\S3\S3Client;
use Vortos\Foundation\Health\Attribute\AsHealthCheck;
use Vortos\Foundation\Health\Contract\HealthCheckInterface;
use Vortos\Foundation\Health\Contract\HealthCheckKind;
use Vortos\Foundation\Health\HealthResult;

/**
 * Monitoring probe for the object store: a `HeadBucket` against the configured bucket.
 *
 * ## Why this is Monitoring and not Readiness
 *
 * It was Readiness, and that was a correlated-failure bug rather than a preference. The bucket is a
 * SHARED external dependency: every replica of every color reaches the same one, so a provider blip
 * fails this probe everywhere at the same instant, the edge finds no healthy upstream, and the whole
 * service goes down — including the majority of requests that never touch object storage at all. A
 * degraded subsystem became a total outage, which is precisely the trade a readiness gate is supposed
 * to avoid.
 *
 * Object-store failure is handled where it can be handled per-operation instead: enable
 * `circuit_breaker` in the object-store config and the calls that actually need the bucket fast-fail
 * while everything else keeps serving. This probe's job is to make the condition VISIBLE — it is
 * sampled by the monitor tick and reported at /health/monitor, and it alerts — never to stop traffic.
 *
 * `critical: true` is retained and still meaningful: it decides fail vs warn on the monitoring
 * surface, so an unreachable bucket is a hard red there rather than a shrug. It just no longer
 * reaches the traffic gate.
 *
 * A footnote on cost, since it is what surfaced this: on Cloudflare R2 every HeadBucket is a billed
 * Class B operation. As a readiness probe it ran on each container's healthcheck interval and each
 * edge active-health interval, forever, on an idle system — millions of operations a month to answer
 * a question nothing was allowed to act on.
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
#[AsHealthCheck(critical: true, timeoutMs: 8000, kind: HealthCheckKind::Monitoring)]
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
