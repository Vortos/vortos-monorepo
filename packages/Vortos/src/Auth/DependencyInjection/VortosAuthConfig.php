<?php
declare(strict_types=1);

namespace Vortos\Auth\DependencyInjection;

use Vortos\Auth\Jwt\Key\Keyring;
use Vortos\Auth\Jwt\Key\KeyStatus;
use Vortos\Auth\Jwt\Key\SigningKey;
use Vortos\Auth\Lockout\LockoutConfig;
use Vortos\Auth\Quota\QuotaFailureMode;
use Vortos\Auth\Storage\InMemoryTokenStorage;

final class VortosAuthConfig
{
    /** @var list<SigningKey> */
    private array $signingKeys = [];
    private bool $exposeJwks = false;
    private int $accessTokenTtl = 900;
    private int $refreshTokenTtl = 604800;
    private string $issuer = 'vortos';
    private string $tokenStorage = InMemoryTokenStorage::class;
    private ?LockoutConfig $lockoutConfig = null;
    private QuotaFailureMode $quotaFailureMode = QuotaFailureMode::FailClosed;
    private bool $quotaHeaders = true;
    private bool $rateLimitHeaders = true;
    private bool $problemDetails = true;
    private string $tenantClaim = 'tenant';

    /**
     * Add a pre-built signing key to the keyring.
     *
     * The keyring needs exactly one Active key; add Next/Retiring keys around a
     * rotation. Order of calls does not matter.
     */
    public function signingKey(SigningKey $key): static
    {
        $this->signingKeys[] = $key;
        return $this;
    }

    /**
     * Add an HS256 shared-secret key. Simple, good for single-service apps.
     * The secret must be at least 64 chars — generate: bin2hex(random_bytes(32)).
     */
    public function hs256(string $secret, string $kid = 'default', KeyStatus $status = KeyStatus::Active): static
    {
        return $this->signingKey(SigningKey::hs256($kid, $secret, $status));
    }

    /**
     * Add an RS256 key pair from PEM strings.
     */
    public function rs256(string $kid, string $privateKeyPem, string $publicKeyPem, KeyStatus $status = KeyStatus::Active): static
    {
        return $this->signingKey(SigningKey::rs256($kid, $privateKeyPem, $publicKeyPem, $status));
    }

    /**
     * Add an RS256 key pair, loading the PEMs from files (kept outside the project root).
     */
    public function rs256FromPaths(string $kid, string $privateKeyPath, string $publicKeyPath, KeyStatus $status = KeyStatus::Active): static
    {
        return $this->rs256(
            $kid,
            $this->readPem($privateKeyPath, 'private'),
            $this->readPem($publicKeyPath, 'public'),
            $status,
        );
    }

    /**
     * Expose the public signing keys at /.well-known/jwks.json (RS256 only).
     */
    public function jwks(bool $enabled = true): static
    {
        $this->exposeJwks = $enabled;
        return $this;
    }

    public function isJwksEnabled(): bool
    {
        return $this->exposeJwks;
    }

    /**
     * Assemble the configured signing keys into a validated Keyring.
     *
     * @throws \LogicException if no signing keys were configured.
     */
    public function buildKeyring(): Keyring
    {
        if ($this->signingKeys === []) {
            throw new \LogicException(
                'Vortos Auth: no JWT signing keys configured. Add ->hs256(...) or ->rs256FromPaths(...) in config/auth.php.'
            );
        }

        return new Keyring(...$this->signingKeys);
    }

    private function readPem(string $path, string $which): string
    {
        if ($path === '' || !is_file($path) || !is_readable($path)) {
            throw new \InvalidArgumentException(
                "Vortos Auth: RS256 {$which} key file not found or not readable at: {$path}"
            );
        }

        return (string) file_get_contents($path);
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

    /**
     * Name of the JWT claim carrying the tenant id, used to populate TenantContext.
     * Default: 'tenant'.
     */
    public function tenantClaim(string $claim): static
    {
        $this->tenantClaim = $claim;
        return $this;
    }

    public function toArray(): array
    {
        return [
            'access_token_ttl'            => $this->accessTokenTtl,
            'refresh_token_ttl'           => $this->refreshTokenTtl,
            'issuer'                      => $this->issuer,
            'token_storage'               => $this->tokenStorage,
            'quota_failure_mode'          => $this->quotaFailureMode->value,
            'quota_headers'               => $this->quotaHeaders,
            'rate_limit_headers'          => $this->rateLimitHeaders,
            'problem_details'             => $this->problemDetails,
            'tenant_claim'                => $this->tenantClaim,
        ];
    }
}
