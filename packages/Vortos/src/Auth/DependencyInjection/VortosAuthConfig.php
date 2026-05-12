<?php
declare(strict_types=1);

namespace Vortos\Auth\DependencyInjection;

use Vortos\Auth\Lockout\LockoutConfig;
use Vortos\Auth\Quota\QuotaFailureMode;
use Vortos\Auth\Storage\InMemoryTokenStorage;

final class VortosAuthConfig
{
    private string $secret = '';
    private int $accessTokenTtl = 900;
    private int $refreshTokenTtl = 604800;
    private string $issuer = 'vortos';
    private string $tokenStorage = InMemoryTokenStorage::class;
    private ?LockoutConfig $lockoutConfig = null;
    private QuotaFailureMode $quotaFailureMode = QuotaFailureMode::FailClosed;
    private bool $quotaHeaders = true;
    private bool $rateLimitHeaders = true;
    private bool $problemDetails = true;

    public function secret(string $secret): static
    {
        $this->secret = $secret;
        return $this;
    }

    public function accessTokenTtl(int $seconds): static
    {
        $this->accessTokenTtl = $seconds;
        return $this;
    }

    public function refreshTokenTtl(int $seconds): static
    {
        $this->refreshTokenTtl = $seconds;
        return $this;
    }

    public function issuer(string $issuer): static
    {
        $this->issuer = $issuer;
        return $this;
    }

    public function tokenStorage(string $storageClass): static
    {
        $this->tokenStorage = $storageClass;
        return $this;
    }

    public function lockout(): LockoutConfig
    {
        if ($this->lockoutConfig === null) {
            $this->lockoutConfig = new LockoutConfig();
        }
        return $this->lockoutConfig;
    }

    public function getLockoutConfig(): ?LockoutConfig
    {
        return $this->lockoutConfig;
    }

    public function quotaFailureMode(QuotaFailureMode $mode): static
    {
        $this->quotaFailureMode = $mode;
        return $this;
    }

    public function quotaHeaders(bool $enabled): static
    {
        $this->quotaHeaders = $enabled;
        return $this;
    }

    public function rateLimitHeaders(bool $enabled): static
    {
        $this->rateLimitHeaders = $enabled;
        return $this;
    }

    public function problemDetails(bool $enabled): static
    {
        $this->problemDetails = $enabled;
        return $this;
    }

    public function toArray(): array
    {
        return [
            'secret'                      => $this->secret,
            'access_token_ttl'            => $this->accessTokenTtl,
            'refresh_token_ttl'           => $this->refreshTokenTtl,
            'issuer'                      => $this->issuer,
            'token_storage'               => $this->tokenStorage,
            'quota_failure_mode'          => $this->quotaFailureMode->value,
            'quota_headers'               => $this->quotaHeaders,
            'rate_limit_headers'          => $this->rateLimitHeaders,
            'problem_details'             => $this->problemDetails,
        ];
    }
}
