<?php
declare(strict_types=1);

namespace Vortos\Auth\DependencyInjection;

use Vortos\Auth\Lockout\LockoutConfig;
use Vortos\Auth\Storage\InMemoryTokenStorage;

final class VortosAuthConfig
{
    private string $secret = '';
    private int $accessTokenTtl = 900;
    private int $refreshTokenTtl = 604800;
    private string $issuer = 'vortos';
    private string $tokenStorage = InMemoryTokenStorage::class;
    private bool $enableBuiltInControllers = false;
    private ?LockoutConfig $lockoutConfig = null;

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

    public function enableBuiltInControllers(bool $enable = true): static
    {
        $this->enableBuiltInControllers = $enable;
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

    public function toArray(): array
    {
        return [
            'secret'                      => $this->secret,
            'access_token_ttl'            => $this->accessTokenTtl,
            'refresh_token_ttl'           => $this->refreshTokenTtl,
            'issuer'                      => $this->issuer,
            'token_storage'               => $this->tokenStorage,
            'enable_built_in_controllers' => $this->enableBuiltInControllers,
        ];
    }
}
