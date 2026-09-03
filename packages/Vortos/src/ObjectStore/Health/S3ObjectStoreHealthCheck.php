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
 * Monitoring probe for the object store: a `HeadObject` on a sentinel key in the configured bucket.
 *
 * ## Why HeadObject, not HeadBucket
 *
 * `HeadBucket` is a bucket-level operation, and a correctly scoped object-store credential is not
 * allowed to make it. On Cloudflare R2 an API token scoped to "Object Read & Write" — the least
 * privilege this application actually needs — is denied every bucket-level call, so `HeadBucket`
 * returns `AccessDenied` while uploads and downloads work perfectly. That is exactly the false
 * positive this probe produced in production: a permanent critical "object store unreachable" on a
 * store that was serving passport scans and logos without a hiccup, because the probe asked a
 * question the credential is designed not to answer.
 *
 * `HeadObject` is an object-level operation, so it exercises the permission the application relies
 * on rather than one it should never hold. The sentinel key does not need to exist: a signed
 * `HeadObject` that reaches the store and comes back `404 Not Found` has already proved everything
 * the probe cares about — the bucket is reachable and the credential is accepted for object access.
 * A `404` is therefore HEALTHY and expected; `403 AccessDenied` is the real "your credentials cannot
 * touch this bucket" signal, and a transport failure is unreachability. The probe never creates the
 * object, so it neither depends on nor mutates bucket contents.
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
 * A footnote on cost: on Cloudflare R2 a HeadObject is a billed Class B operation, the same class
 * and the same one-per-run as the HeadBucket it replaces, so the switch is cost-neutral. (The
 * separate move of this probe off the readiness gate — see below — is what stopped it running on
 * every container and edge health interval; that reduction stands regardless of the verb.)
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
        // A key the probe HEADs. It is never written, so it need not exist — a 404 is the proof of
        // reachability we want. It carries the configured key prefix so a prefix-scoped credential
        // (a token allowed only under `orgs/…`, say) is exercised inside its own grant rather than
        // being denied at the root and read as an outage.
        private readonly string $probeKey = '.vortos-health-probe',
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
                $this->client->headObject(['Bucket' => $this->bucket, 'Key' => $this->probeKey]);

                return $this->reachable($start);
            } catch (AwsException $e) {
                // A 404 is the healthy answer, not a failure: the request was signed, reached the
                // store, and the store authoritatively said "no such object". Reachability and
                // object-level authorization are both proved; the sentinel simply is not there, by
                // design. Only 403 (the credential cannot touch the bucket) and transport failures
                // are genuine unhealth.
                if ($e->getStatusCode() === 404) {
                    return $this->reachable($start);
                }
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

    private function reachable(int $start): HealthResult
    {
        return new HealthResult(
            name: $this->name(),
            healthy: true,
            latencyMs: $this->ms($start),
            error: $this->provider === 'r2' ? 'Cloudflare R2 bucket reachable.' : null,
            errorCode: $this->provider === 'r2' ? 'object_store_r2_reachable' : null,
            critical: true,
        );
    }

    private function ms(int $start): float
    {
        return round((hrtime(true) - $start) / 1_000_000, 2);
    }
}
