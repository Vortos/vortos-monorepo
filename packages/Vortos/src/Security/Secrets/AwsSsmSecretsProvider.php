<?php

declare(strict_types=1);

namespace Vortos\Security\Secrets;

use Vortos\Security\Contract\SecretsInterface;

/**
 * Reads secrets from AWS Systems Manager Parameter Store.
 *
 * Requires aws/aws-sdk-php: composer require aws/aws-sdk-php
 *
 * ## Authentication
 *
 * Uses the AWS SDK's default credential resolution chain:
 *   1. Environment variables (AWS_ACCESS_KEY_ID, AWS_SECRET_ACCESS_KEY)
 *   2. IAM role attached to the EC2/ECS/Lambda instance
 *   3. ~/.aws/credentials file
 *
 * In production, use IAM roles (instance profiles) — never hardcode credentials.
 *
 * ## SecureString parameters
 *
 * SecureString parameters are automatically decrypted using the KMS key
 * associated with the parameter. The IAM role must have kms:Decrypt permission.
 *
 * ## Key format
 *
 * The $awsPrefix (configured in VortosSecurityConfig) is prepended to every key:
 *   get('db/password') with prefix '/myapp/' → fetches '/myapp/db/password'
 *
 * ## Caching
 *
 * Fetched parameters are cached in-process for $cacheTtl seconds.
 */
final class AwsSsmSecretsProvider implements SecretsInterface
{
    /** @var array<string, array{value: string, expires_at: int}> */
    private array $cache = [];

    private mixed $client = null;

    public function __construct(
        private readonly string $region,
        private readonly string $prefix,
        private readonly int    $cacheTtl,
    ) {}

    public function get(string $key): string
    {
        $fullKey = rtrim($this->prefix, '/') . '/' . ltrim($key, '/');

        if (isset($this->cache[$fullKey]) && $this->cache[$fullKey]['expires_at'] > time()) {
            return $this->cache[$fullKey]['value'];
        }

        $value = $this->fetchParameter($fullKey);

        if ($this->cacheTtl > 0) {
            $this->cache[$fullKey] = [
                'value'      => $value,
                'expires_at' => time() + $this->cacheTtl,
            ];
        }

        return $value;
    }

    private function fetchParameter(string $name): string
    {
        $client = $this->getClient();

        try {
            $result = $client->getParameter([
                'Name'           => $name,
                'WithDecryption' => true,
            ]);
            return (string) ($result['Parameter']['Value'] ?? '');
        } catch (\Exception) {
            return '';
        }
    }

    private function getClient(): mixed
    {
        if ($this->client !== null) {
            return $this->client;
        }

        if (!class_exists(\Aws\Ssm\SsmClient::class)) {
            throw new \RuntimeException(
                'vortos-security: AwsSsmSecretsProvider requires aws/aws-sdk-php. '
                . 'Run: composer require aws/aws-sdk-php'
            );
        }

        $this->client = new \Aws\Ssm\SsmClient([
            'version' => 'latest',
            'region'  => $this->region ?: ($_ENV['AWS_DEFAULT_REGION'] ?? 'us-east-1'),
        ]);

        return $this->client;
    }
}
