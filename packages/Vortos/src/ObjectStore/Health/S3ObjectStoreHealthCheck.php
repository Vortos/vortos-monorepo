<?php

declare(strict_types=1);

namespace Vortos\ObjectStore\Health;

use Aws\Exception\AwsException;
use Aws\S3\S3Client;
use Vortos\Foundation\Health\Attribute\AsHealthCheck;
use Vortos\Foundation\Health\Contract\HealthCheckInterface;
use Vortos\Foundation\Health\HealthResult;

#[AsHealthCheck(critical: true, timeoutMs: 5000)]
final class S3ObjectStoreHealthCheck implements HealthCheckInterface
{
    public function __construct(
        private readonly S3Client $client,
        private readonly string $bucket,
        private readonly string $provider = 's3',
    ) {}

    public function name(): string
    {
        return 'object_store';
    }

    public function check(): HealthResult
    {
        $start = hrtime(true);

        try {
            $this->client->headBucket(['Bucket' => $this->bucket]);
        } catch (AwsException $e) {
            return new HealthResult(
                name: $this->name(),
                healthy: false,
                latencyMs: $this->ms($start),
                error: $e->getAwsErrorMessage() ?? $e->getMessage(),
                errorCode: 'object_store_unreachable',
            );
        } catch (\Throwable $e) {
            return new HealthResult(
                name: $this->name(),
                healthy: false,
                latencyMs: $this->ms($start),
                error: $e->getMessage(),
                errorCode: 'object_store_unreachable',
            );
        }

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
