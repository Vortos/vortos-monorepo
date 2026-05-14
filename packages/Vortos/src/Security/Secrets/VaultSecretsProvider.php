<?php

declare(strict_types=1);

namespace Vortos\Security\Secrets;

use Vortos\Security\Contract\SecretsInterface;

/**
 * Reads secrets from HashiCorp Vault via the HTTP API v1.
 *
 * ## Authentication
 *
 * Supports two methods (use AppRole in production):
 *
 *   1. Static token (dev/simple setups):
 *      $config->secrets()->vaultAddr('https://vault:8200')->vaultToken('hvs.xxx')
 *
 *   2. AppRole (recommended for production):
 *      $config->secrets()->vaultAddr('...')->roleId('...')->secretId('env:VAULT_SECRET_ID')
 *      AppRole issues a short-lived token that is automatically renewed.
 *
 * ## Secret path format
 *
 * For KV v2 secrets engine (the default):
 *   get('secret/data/myapp/db#password')
 *        ^path to secret    ^field within the secret
 *
 * For KV v1:
 *   get('secret/myapp/db#password')
 *
 * ## Caching
 *
 * Fetched secrets are cached in-process for $cacheTtl seconds.
 * Set cacheTtl(0) to disable caching (not recommended in production).
 */
final class VaultSecretsProvider implements SecretsInterface
{
    /** @var array<string, array{value: string, expires_at: int}> */
    private array $cache = [];

    private ?string $activeToken = null;
    private int     $tokenExpiresAt = 0;

    public function __construct(
        private readonly string $vaultAddr,
        private readonly string $staticToken,
        private readonly string $roleId,
        private readonly string $secretId,
        private readonly int    $cacheTtl,
    ) {}

    public function get(string $key): string
    {
        if (isset($this->cache[$key]) && $this->cache[$key]['expires_at'] > time()) {
            return $this->cache[$key]['value'];
        }

        // Parse 'path/to/secret#field'
        $parts = explode('#', $key, 2);
        $path  = $parts[0];
        $field = $parts[1] ?? 'value';

        $token  = $this->resolveToken();
        $secret = $this->fetchSecret($path, $field, $token);

        if ($this->cacheTtl > 0) {
            $this->cache[$key] = [
                'value'      => $secret,
                'expires_at' => time() + $this->cacheTtl,
            ];
        }

        return $secret;
    }

    private function fetchSecret(string $path, string $field, string $token): string
    {
        $url      = rtrim($this->vaultAddr, '/') . '/v1/' . ltrim($path, '/');
        $response = $this->httpGet($url, $token);

        if ($response === null) {
            return '';
        }

        $data = json_decode($response, true);

        // KV v2 wraps data in data.data
        $secret = $data['data']['data'][$field]
            ?? $data['data'][$field]   // KV v1
            ?? '';

        return (string) $secret;
    }

    private function resolveToken(): string
    {
        if ($this->staticToken !== '') {
            return $this->staticToken;
        }

        // AppRole: issue or reuse token
        if ($this->activeToken !== null && $this->tokenExpiresAt > time() + 60) {
            return $this->activeToken;
        }

        [$token, $leaseDuration] = $this->appRoleLogin();
        $this->activeToken    = $token;
        $this->tokenExpiresAt = time() + max(60, $leaseDuration);
        return $this->activeToken;
    }

    /** @return array{0: string, 1: int} [client_token, lease_duration_seconds] */
    private function appRoleLogin(): array
    {
        $secretId = str_starts_with($this->secretId, 'env:')
            ? ($_ENV[substr($this->secretId, 4)] ?? '')
            : $this->secretId;

        $url     = rtrim($this->vaultAddr, '/') . '/v1/auth/approle/login';
        $payload = json_encode(['role_id' => $this->roleId, 'secret_id' => $secretId]);

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER  => true,
            CURLOPT_POST            => true,
            CURLOPT_POSTFIELDS      => $payload,
            CURLOPT_TIMEOUT         => 5,
            CURLOPT_HTTPHEADER      => ['Content-Type: application/json'],
            CURLOPT_SSL_VERIFYPEER  => true,
            CURLOPT_SSL_VERIFYHOST  => 2,
        ]);

        $response = curl_exec($ch);
        curl_close($ch);

        if (!$response) {
            throw new \RuntimeException('vortos-security: Vault AppRole login failed — no response.');
        }

        $data = json_decode((string) $response, true);
        $token    = $data['auth']['client_token'] ?? throw new \RuntimeException(
            'vortos-security: Vault AppRole login failed — no client_token in response.'
        );
        $leaseDuration = (int) ($data['auth']['lease_duration'] ?? 3600);

        return [$token, $leaseDuration];
    }

    private function httpGet(string $url, string $token): ?string
    {
        if (!function_exists('curl_init')) {
            throw new \RuntimeException('vortos-security: VaultSecretsProvider requires ext-curl.');
        }

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 5,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_HTTPHEADER     => [
                'X-Vault-Token: ' . $token,
                'X-Vault-Request: true',
            ],
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        return ($response !== false && $httpCode === 200) ? (string) $response : null;
    }
}
