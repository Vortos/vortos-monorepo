<?php

declare(strict_types=1);

namespace Vortos\Secrets\Service;

use DateTimeImmutable;
use Vortos\Secrets\Provider\SecretsProviderInterface;
use Vortos\Secrets\Rotation\RotationPolicy;
use Vortos\Secrets\Rotation\RotationResult;
use Vortos\Secrets\Value\SecretKey;

/**
 * Orchestrates two-phase rotation: decides WHETHER a secret is due, and delegates
 * the actual issue-new/retain-previous-during-grace mechanics to the provider
 * (already two-phase per {@see SecretsProviderInterface::rotate()}). Keeping the
 * "is it due" policy decision here — separate from the provider — lets a
 * scheduled job call {@see rotateIfDue()} unconditionally without each provider
 * driver reimplementing the same due-date arithmetic.
 */
final class RotationManager
{
    public function __construct(private readonly SecretsProviderInterface $provider) {}

    /** Rotates only if the current version is due per the policy; null otherwise. */
    public function rotateIfDue(SecretKey $key, RotationPolicy $policy, DateTimeImmutable $now): ?RotationResult
    {
        $current = $this->provider->versions($key)->current();

        if (!$policy->isDue($current, $now)) {
            return null;
        }

        return $this->provider->rotate($key, $policy);
    }

    /** Rotates unconditionally, regardless of due-date — an explicit operator action. */
    public function forceRotate(SecretKey $key, RotationPolicy $policy): RotationResult
    {
        return $this->provider->rotate($key, $policy);
    }
}
