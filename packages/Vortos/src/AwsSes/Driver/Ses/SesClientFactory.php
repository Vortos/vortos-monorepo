<?php

declare(strict_types=1);

namespace Vortos\AwsSes\Driver\Ses;

use Aws\SesV2\SesV2Client;

/**
 * Builds an SesV2Client from DI parameters.
 *
 * AWS credentials are resolved by the SDK in this order:
 *   1. Environment variables (AWS_ACCESS_KEY_ID, AWS_SECRET_ACCESS_KEY)
 *   2. AWS credentials file (~/.aws/credentials)
 *   3. EC2/ECS instance metadata (IAM role)
 *
 * endpointOverride routes all API calls to a custom SES-compatible URL.
 */
final class SesClientFactory
{
    public static function create(
        string $region,
        ?string $endpointOverride,
        float $httpTimeout,
        int $maxRetries,
    ): SesV2Client {
        $config = [
            'region'  => $region,
            'version' => 'latest',
            'http'    => [
                'timeout'         => $httpTimeout,
                'connect_timeout' => 2.0,
            ],
            'retries' => [
                'mode'        => 'standard',
                'max_attempts' => $maxRetries,
            ],
        ];

        if ($endpointOverride !== null) {
            $config['endpoint'] = $endpointOverride;
        }

        return new SesV2Client($config);
    }
}
