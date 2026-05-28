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
        $endpoint ??= self::deriveEndpoint($provider, $accountId);

        $config = [
            'region' => $region,
            'version' => 'latest',
            'http' => [
                'timeout' => $httpTimeout,
                'connect_timeout' => $connectTimeout,
            ],
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
