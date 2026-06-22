<?php

declare(strict_types=1);

namespace Vortos\FeatureFlags;

/**
 * The evaluation context for a single flag lookup.
 *
 * Security — trust zones (PLATFORM §6, see WIRE_CONTRACT.md):
 * `X-Vortos-Flag-Context` is **attacker-controlled** input. Fields are split into:
 *
 *  - `trusted`   — derived server-side from the authenticated identity
 *                  (tenantId, roles, plan, federationId, entitlements…). A free-tier
 *                  user can NOT escalate by spoofing these.
 *  - `untrusted` — legitimately client-owned, non-entitlement signals
 *                  (country, deviceId, sessionId, UI attributes).
 *
 * Rules read the trusted zone by default; reading the untrusted zone is explicit and
 * is forbidden for permission-kind flags (enforced from Block 5 onward). The legacy
 * `attributes` argument is preserved for back-compat and treated as untrusted.
 */
final class FlagContext
{
    /**
     * @param array<string,mixed> $attributes legacy/untrusted attribute bag (back-compat)
     * @param array<string,mixed> $trusted    server-derived, entitlement-bearing fields
     * @param array<string,mixed> $untrusted  client-derived, non-entitlement fields
     */
    public function __construct(
        public readonly ?string $userId = null,
        public readonly array $attributes = [],
        public readonly array $trusted = [],
        public readonly array $untrusted = [],
    ) {}

    /**
     * Merged read for backward compatibility. Precedence: trusted > untrusted > legacy.
     * Prefer {@see getTrusted()} / {@see getUntrusted()} in new rule code so the trust
     * zone is explicit.
     */
    public function getAttribute(string $key): mixed
    {
        return $this->trusted[$key]
            ?? $this->untrusted[$key]
            ?? $this->attributes[$key]
            ?? null;
    }

    /** Read a field that must originate from the authenticated identity. */
    public function getTrusted(string $key): mixed
    {
        return $this->trusted[$key] ?? null;
    }

    /** Read a client-owned, non-entitlement field (also falls back to legacy attributes). */
    public function getUntrusted(string $key): mixed
    {
        return $this->untrusted[$key] ?? $this->attributes[$key] ?? null;
    }

    public function hasTrusted(string $key): bool
    {
        return array_key_exists($key, $this->trusted);
    }

    /**
     * Resolve the value of a bucketing dimension, honouring trust zones:
     * identity-bound keys (user/tenant/account) come from the trusted zone; client-owned
     * keys (device/session) from the untrusted zone. Returns null when the dimension is
     * absent → the caller safe-defaults (no rollout match / control variant).
     */
    public function bucketingValue(string $bucketBy): ?string
    {
        $value = match ($bucketBy) {
            FeatureFlag::BUCKET_BY_USER    => $this->userId,
            FeatureFlag::BUCKET_BY_TENANT  => $this->getTrusted('tenantId'),
            FeatureFlag::BUCKET_BY_ACCOUNT => $this->getTrusted('accountId'),
            FeatureFlag::BUCKET_BY_DEVICE  => $this->getUntrusted('deviceId'),
            FeatureFlag::BUCKET_BY_SESSION => $this->getUntrusted('sessionId'),
            default                        => $this->userId,
        };

        return $value !== null ? (string) $value : null;
    }

    /**
     * A stable, collision-resistant fingerprint of the whole context — used by the
     * per-request memo so a cached result can never cross a context boundary.
     */
    public function cacheKey(): string
    {
        return ($this->userId ?? '__anon__') . '|' . hash(
            'xxh3',
            json_encode([
                't' => $this->trusted,
                'u' => $this->untrusted,
                'a' => $this->attributes,
            ], JSON_THROW_ON_ERROR),
        );
    }
}
