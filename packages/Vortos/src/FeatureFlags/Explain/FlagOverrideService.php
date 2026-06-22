<?php

declare(strict_types=1);

namespace Vortos\FeatureFlags\Explain;

use Symfony\Contracts\Service\ResetInterface;
use Vortos\FeatureFlags\FlagValue;
use Vortos\FeatureFlags\FlagValueType;

/**
 * Per-request flag override for QA — forces a flag value without editing rules.
 *
 * Security hardening:
 * - Disabled by default; must be explicitly enabled via constructor.
 * - Only active in non-production environments when `$allowedEnvironments` matches.
 * - Override tokens are HMAC-signed to prevent unauthorized use.
 * - Every applied override is recorded for audit purposes.
 * - Implements {@see ResetInterface} to clear state between requests.
 */
final class FlagOverrideService implements ResetInterface
{
    /** @var array<string,FlagValue> flag name → forced value for this request */
    private array $overrides = [];

    /** @var list<array{flag:string,value:mixed,actor:?string,timestamp:string}> */
    private array $auditLog = [];

    /**
     * @param bool   $enabled              master switch — false = overrides silently ignored
     * @param string $secret               HMAC secret for signing override tokens (min 32 bytes)
     * @param list<string> $allowedEnvironments environments where overrides are permitted
     */
    public function __construct(
        private readonly bool $enabled = false,
        private readonly string $secret = '',
        private readonly array $allowedEnvironments = ['development', 'staging', 'test'],
    ) {}

    public function isEnabled(): bool
    {
        return $this->enabled && $this->secret !== '';
    }

    /**
     * Validate an override token and apply the override if valid.
     *
     * Token format: `base64(json({"flag":"x","value":"y","exp":unix_ts})).hmac_hex`
     *
     * @return bool true if the override was applied
     */
    public function applyFromToken(string $token, string $currentEnvironment, ?string $actorId = null): bool
    {
        if (!$this->isEnabled()) {
            return false;
        }

        if (!in_array($currentEnvironment, $this->allowedEnvironments, true)) {
            return false;
        }

        $parts = explode('.', $token, 2);
        if (count($parts) !== 2) {
            return false;
        }

        [$payloadB64, $signature] = $parts;

        $expectedSig = hash_hmac('sha256', $payloadB64, $this->secret);
        if (!hash_equals($expectedSig, $signature)) {
            return false;
        }

        $decoded = base64_decode($payloadB64, true);
        if ($decoded === false) {
            return false;
        }

        try {
            $data = json_decode($decoded, true, 8, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return false;
        }

        if (!is_array($data) || !isset($data['flag']) || !is_string($data['flag'])) {
            return false;
        }

        // Expiry check
        if (isset($data['exp']) && is_int($data['exp']) && $data['exp'] < time()) {
            return false;
        }

        $flagName = $data['flag'];
        $rawValue = $data['value'] ?? true;

        $value = match (true) {
            is_bool($rawValue)              => FlagValue::bool($rawValue),
            is_string($rawValue)            => FlagValue::string($rawValue),
            is_int($rawValue), is_float($rawValue) => FlagValue::number($rawValue),
            is_array($rawValue)             => FlagValue::json($rawValue),
            default                         => FlagValue::bool(true),
        };

        $this->overrides[$flagName] = $value;
        $this->auditLog[] = [
            'flag'      => $flagName,
            'value'     => $rawValue,
            'actor'     => $actorId,
            'timestamp' => date(\DateTimeInterface::ATOM),
        ];

        return true;
    }

    /**
     * Create a signed override token.
     *
     * @param int $ttlSeconds how long the token is valid (default 1 hour)
     */
    public function createToken(string $flagName, mixed $value = true, int $ttlSeconds = 3600): string
    {
        $payload = json_encode([
            'flag'  => $flagName,
            'value' => $value,
            'exp'   => time() + $ttlSeconds,
        ], JSON_THROW_ON_ERROR);

        $payloadB64 = base64_encode($payload);
        $signature  = hash_hmac('sha256', $payloadB64, $this->secret);

        return $payloadB64 . '.' . $signature;
    }

    public function hasOverride(string $flagName): bool
    {
        return isset($this->overrides[$flagName]);
    }

    public function getOverride(string $flagName): ?FlagValue
    {
        return $this->overrides[$flagName] ?? null;
    }

    /** @return list<array{flag:string,value:mixed,actor:?string,timestamp:string}> */
    public function auditLog(): array
    {
        return $this->auditLog;
    }

    public function reset(): void
    {
        $this->overrides = [];
        $this->auditLog  = [];
    }
}
