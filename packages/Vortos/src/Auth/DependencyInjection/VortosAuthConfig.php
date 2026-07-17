<?php
declare(strict_types=1);

namespace Vortos\Auth\DependencyInjection;

use Vortos\Auth\Audit\AuditFailureMode;
use Vortos\Auth\Jwt\Key\Keyring;
use Vortos\Auth\Jwt\Key\KeyStatus;
use Vortos\Auth\Jwt\Key\SigningKey;
use Vortos\Auth\Lockout\LockoutConfig;
use Vortos\Auth\Lockout\LockoutFailureMode;
use Vortos\Auth\Quota\QuotaFailureMode;
use Vortos\Auth\RateLimit\RateLimitFailureConfig;
use Vortos\Auth\RateLimit\RateLimitFailureMode;
use Vortos\Auth\Storage\InMemoryTokenStorage;
use Symfony\Component\DependencyInjection\Definition;

final class VortosAuthConfig
{
    /** @var list<SigningKey> */
    private array $signingKeys = [];
    private bool $exposeJwks = false;
    private int $accessTokenTtl = 900;
    private int $refreshTokenTtl = 604800;
    private int $refreshRotationGraceSeconds = 0;
    private bool $enforceSessionLiveness = false;
    private int $sessionLivenessCacheTtlSeconds = 5;
    private int $sessionLivenessCircuitBreakerThreshold = 5;
    private int $sessionLivenessCircuitBreakerResetSeconds = 30;
    private string $issuer = 'vortos';
    private string $audience = 'vortos';
    private string $tokenStorage = InMemoryTokenStorage::class;
    private ?LockoutConfig $lockoutConfig = null;
    private LockoutFailureMode $lockoutFailureMode = LockoutFailureMode::FailClosed;
    private int $lockoutCircuitBreakerThreshold = 3;
    private int $lockoutCircuitBreakerResetSeconds = 30;
    private QuotaFailureMode $quotaFailureMode = QuotaFailureMode::FailClosed;
    private bool $quotaHeaders = true;
    private bool $quotaCompensateOnServerError = true;
    private bool $rateLimitHeaders = true;
    private RateLimitFailureMode $rateLimitFailureModeIp = RateLimitFailureMode::FailClosed;
    private RateLimitFailureMode $rateLimitFailureModeGlobal = RateLimitFailureMode::FailClosed;
    private RateLimitFailureMode $rateLimitFailureModeUser = RateLimitFailureMode::FailOpen;
    private int $rateLimitCircuitBreakerThreshold = 5;
    private int $rateLimitCircuitBreakerResetSeconds = 30;
    private bool $problemDetails = true;
    private AuditFailureMode $auditFailureMode = AuditFailureMode::FailClosed;
    private string $auditHmacKey = '';
    private ?int $apiKeyMaxInactivitySeconds = null;
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

    /**
     * The keyring as an inline, dumpable {@see Definition} (B21). The prod HTTP container is cached
     * via PhpDumper, which cannot serialise a raw Keyring/SigningKey object argument; each signing key
     * is instead expressed as a Definition using SigningKey's own static factory with scalar + enum
     * args, so the whole graph is dumpable while the config API stays string-based.
     */
    public function keyringDefinition(): Definition
    {
        if ($this->signingKeys === []) {
            throw new \LogicException(
                'Vortos Auth: no JWT signing keys configured. Add ->hs256(...) or ->rs256FromPaths(...) in config/auth.php.'
            );
        }

        $keyArgs = [];
        foreach ($this->signingKeys as $key) {
            $keyArgs[] = $key->isRsa()
                ? (new Definition(SigningKey::class))
                    ->setFactory([SigningKey::class, 'rs256'])
                    ->setArguments([$key->kid, $key->privateKey, $key->publicKey, $key->status])
                : (new Definition(SigningKey::class))
                    ->setFactory([SigningKey::class, 'hs256'])
                    ->setArguments([$key->kid, $key->secret, $key->status]);
        }

        return (new Definition(Keyring::class))->setArguments($keyArgs);
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

    /**
     * Grace window (seconds) during which a just-rotated refresh token may be presented
     * again without being treated as reuse/theft. Absorbs benign races — concurrent
     * browser tabs refreshing at once, or a refresh request retried after a flaky-network
     * timeout — that would otherwise trip strict one-time-use detection and revoke every
     * session for the user.
     *
     * 0 (default) preserves strict one-time-use with no grace. Keep the window small
     * (e.g. 30) so it never becomes a meaningful theft window.
     */
    public function refreshRotationGraceSeconds(int $seconds): static
    {
        $this->refreshRotationGraceSeconds = max(0, $seconds);
        return $this;
    }

    /**
     * When true, every authenticated request verifies that the access token's session
     * (the `sid` claim) is still live in the session store, returning 401 the moment a
     * session is revoked rather than waiting for the access token to expire. Costs one
     * session-store lookup per authenticated request and requires Redis + a SessionPolicy.
     * Fails open on store errors. Default: false (stateless access tokens).
     */
    public function enforceSessionLiveness(bool $enabled = true): static
    {
        $this->enforceSessionLiveness = $enabled;
        return $this;
    }

    /**
     * Positive-cache window (seconds) for session-liveness checks — see enforceSessionLiveness().
     * Also the upper bound on revocation latency. 0 disables the cache (strict per-request).
     */
    public function sessionLivenessCacheTtlSeconds(int $seconds): static
    {
        $this->sessionLivenessCacheTtlSeconds = max(0, $seconds);
        return $this;
    }

    public function sessionLivenessCircuitBreaker(int $failureThreshold, int $resetSeconds): static
    {
        $this->sessionLivenessCircuitBreakerThreshold = max(1, $failureThreshold);
        $this->sessionLivenessCircuitBreakerResetSeconds = max(1, $resetSeconds);
        return $this;
    }

    public function issuer(string $issuer): static
    {
        $this->issuer = $issuer;
        return $this;
    }

    public function audience(string $audience): static
    {
        $this->audience = $audience;
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

    public function lockoutFailureMode(LockoutFailureMode $mode): static
    {
        $this->lockoutFailureMode = $mode;
        return $this;
    }

    public function lockoutCircuitBreakerThreshold(int $threshold): static
    {
        $this->lockoutCircuitBreakerThreshold = $threshold;
        return $this;
    }

    public function lockoutCircuitBreakerResetSeconds(int $seconds): static
    {
        $this->lockoutCircuitBreakerResetSeconds = $seconds;
        return $this;
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

    public function quotaCompensateOnServerError(bool $enabled): static
    {
        $this->quotaCompensateOnServerError = $enabled;
        return $this;
    }

    public function rateLimitHeaders(bool $enabled): static
    {
        $this->rateLimitHeaders = $enabled;
        return $this;
    }

    public function rateLimitFailureModeIp(RateLimitFailureMode $mode): static
    {
        $this->rateLimitFailureModeIp = $mode;
        return $this;
    }

    public function rateLimitFailureModeGlobal(RateLimitFailureMode $mode): static
    {
        $this->rateLimitFailureModeGlobal = $mode;
        return $this;
    }

    public function rateLimitFailureModeUser(RateLimitFailureMode $mode): static
    {
        $this->rateLimitFailureModeUser = $mode;
        return $this;
    }

    public function rateLimitCircuitBreakerThreshold(int $threshold): static
    {
        $this->rateLimitCircuitBreakerThreshold = $threshold;
        return $this;
    }

    public function rateLimitCircuitBreakerResetSeconds(int $seconds): static
    {
        $this->rateLimitCircuitBreakerResetSeconds = $seconds;
        return $this;
    }

    public function problemDetails(bool $enabled): static
    {
        $this->problemDetails = $enabled;
        return $this;
    }

    public function auditFailureMode(AuditFailureMode $mode): static
    {
        $this->auditFailureMode = $mode;
        return $this;
    }

    public function auditHmacKey(string $key): static
    {
        $this->auditHmacKey = $key;
        return $this;
    }

    /**
     * Maximum seconds an API key can go unused before it is rejected.
     * Null disables inactivity expiry. Recommended: 7776000 (90 days).
     */
    public function apiKeyMaxInactivitySeconds(?int $seconds): static
    {
        $this->apiKeyMaxInactivitySeconds = $seconds;
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
            'refresh_rotation_grace_seconds' => $this->refreshRotationGraceSeconds,
            'enforce_session_liveness'    => $this->enforceSessionLiveness,
            'session_liveness_cache_ttl_seconds' => $this->sessionLivenessCacheTtlSeconds,
            'session_liveness_circuit_breaker_threshold' => $this->sessionLivenessCircuitBreakerThreshold,
            'session_liveness_circuit_breaker_reset_seconds' => $this->sessionLivenessCircuitBreakerResetSeconds,
            'issuer'                      => $this->issuer,
            'audience'                    => $this->audience,
            'token_storage'               => $this->tokenStorage,
            'lockout_failure_mode'        => $this->lockoutFailureMode->value,
            'lockout_circuit_breaker_threshold' => $this->lockoutCircuitBreakerThreshold,
            'lockout_circuit_breaker_reset_seconds' => $this->lockoutCircuitBreakerResetSeconds,
            'quota_failure_mode'          => $this->quotaFailureMode->value,
            'quota_headers'               => $this->quotaHeaders,
            'quota_compensate_on_server_error' => $this->quotaCompensateOnServerError,
            'rate_limit_headers'                     => $this->rateLimitHeaders,
            'rate_limit_failure_mode_ip'              => $this->rateLimitFailureModeIp->value,
            'rate_limit_failure_mode_global'          => $this->rateLimitFailureModeGlobal->value,
            'rate_limit_failure_mode_user'            => $this->rateLimitFailureModeUser->value,
            'rate_limit_circuit_breaker_threshold'    => $this->rateLimitCircuitBreakerThreshold,
            'rate_limit_circuit_breaker_reset_seconds' => $this->rateLimitCircuitBreakerResetSeconds,
            'problem_details'                        => $this->problemDetails,
            'audit_failure_mode'                     => $this->auditFailureMode->value,
            'audit_hmac_key'                         => $this->auditHmacKey,
            'api_key_max_inactivity_seconds'         => $this->apiKeyMaxInactivitySeconds ?? -1,
            'tenant_claim'                           => $this->tenantClaim,
        ];
    }
}
