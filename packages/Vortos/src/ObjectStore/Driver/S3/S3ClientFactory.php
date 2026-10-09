<?php

declare(strict_types=1);

namespace Vortos\ObjectStore\Driver\S3;

use Aws\S3\S3Client;
use Vortos\ObjectStore\Exception\ObjectStoreConfigurationException;

final class S3ClientFactory
{
    public static function create(
        string $provider,
        string $region,
        ?string $endpoint,
        ?string $accountId,
        ?string $accessKeyId,
        ?string $secretAccessKey,
        float $httpTimeout,
        float $connectTimeout,
        int $maxRetries,
        bool $pathStyleEndpoint,
    ): S3Client {
        return self::build($provider, $region, $endpoint, $accountId, $accessKeyId, $secretAccessKey, [
            'timeout' => $httpTimeout,
            'connect_timeout' => $connectTimeout,
        ], $maxRetries, $pathStyleEndpoint);
    }

    /**
     * A client for transfers whose healthy duration grows with the data — WAL segments, base
     * backups, dumps. See ObjectStoreBulkTransferConfig for why it has no total timeout.
     *
     * The stall rule is set twice because Guzzle picks the handler, not us. The curl handlers honour
     * CURLOPT_LOW_SPEED_LIMIT/TIME: abort when fewer than `$stallMinBytesPerSecond` bytes a second
     * move for `$stallTimeoutSeconds`. The PHP stream handler — used without ext-curl, or for a
     * `stream => true` request — ignores `curl` and honours `read_timeout`, the idle time allowed
     * between two reads. Either way a moving transfer is never cut and a dead one always is; without
     * the second, a stream-handler request with no total timeout could wait forever.
     */
    public static function createForBulkTransfer(
        string $provider,
        string $region,
        ?string $endpoint,
        ?string $accountId,
        ?string $accessKeyId,
        ?string $secretAccessKey,
        float $connectTimeout,
        int $stallTimeoutSeconds,
        int $stallMinBytesPerSecond,
        int $maxRetries,
        bool $pathStyleEndpoint,
    ): S3Client {
        if ($stallTimeoutSeconds < 1 || $stallMinBytesPerSecond < 1) {
            throw new ObjectStoreConfigurationException('Bulk transfer stall detection needs a positive window and rate.');
        }

        $http = [
            'timeout' => 0,
            'connect_timeout' => $connectTimeout,
            'read_timeout' => (float) $stallTimeoutSeconds,
        ];

        // Without ext-curl Guzzle cannot pick a curl handler, and the constants do not exist.
        if (\defined('CURLOPT_LOW_SPEED_LIMIT')) {
            $http['curl'] = [
                \CURLOPT_LOW_SPEED_LIMIT => $stallMinBytesPerSecond,
                \CURLOPT_LOW_SPEED_TIME => $stallTimeoutSeconds,
            ];
        }

        return self::build($provider, $region, $endpoint, $accountId, $accessKeyId, $secretAccessKey, $http, $maxRetries, $pathStyleEndpoint);
    }

    /** @param array<string, mixed> $http */
    private static function build(
        string $provider,
        string $region,
        ?string $endpoint,
        ?string $accountId,
        ?string $accessKeyId,
        ?string $secretAccessKey,
        array $http,
        int $maxRetries,
        bool $pathStyleEndpoint,
    ): S3Client {
        $endpoint ??= self::deriveEndpoint($provider, $accountId);

        $config = [
            'region' => $region,
            'version' => 'latest',
            'http' => $http,
            'retries' => [
                'mode' => 'standard',
                'max_attempts' => $maxRetries,
            ],
            'use_path_style_endpoint' => $pathStyleEndpoint,
        ];

        if ($endpoint !== null) {
            $config['endpoint'] = $endpoint;
        }

        if ($accessKeyId !== null && $secretAccessKey !== null) {
            $config['credentials'] = [
                'key' => $accessKeyId,
                'secret' => $secretAccessKey,
            ];
        }

        return new S3Client($config);
    }

    private static function deriveEndpoint(string $provider, ?string $accountId): ?string
    {
        if ($provider !== 'r2') {
            return null;
        }

        if ($accountId === null || $accountId === '') {
            throw new ObjectStoreConfigurationException(
                'Cloudflare R2 requires OBJECT_STORE_ENDPOINT or OBJECT_STORE_ACCOUNT_ID.',
            );
        }

        return sprintf('https://%s.r2.cloudflarestorage.com', $accountId);
    }
}
