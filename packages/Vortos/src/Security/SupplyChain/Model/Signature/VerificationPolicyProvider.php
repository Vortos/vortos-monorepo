<?php

declare(strict_types=1);

namespace Vortos\Security\SupplyChain\Model\Signature;

/**
 * Resolves the deploy-time signature verification policy at RUNTIME.
 *
 * WHY A PROVIDER AND NOT A ?VerificationPolicy ARGUMENT
 *
 * SignatureVerificationCheck took a nullable policy that nothing ever supplied, so it reported
 * "No verification policy configured — supply-chain signature check skipped" on every deploy. The
 * build has been signing images with cosign keyless and verifying them in CI the whole time; what
 * was missing was the gate at deploy time. An unsigned or foreign-signed image would have been
 * released without complaint.
 *
 * The configuration has to come from the environment, and it must not be read while the container
 * is compiled — that is the defect this codebase has hit repeatedly (see FB-36 and the alerts
 * extension): a compile-time read resolves against whichever host built the container. So the
 * values arrive as declared `%env(...)%` arguments and the decision is made here, when asked.
 *
 * Unset means unset: a deployment that has not configured verification still skips rather than
 * failing closed on a policy nobody wrote. Once issuer + SAN regex (or a fingerprint) are set, the
 * check enforces.
 */
final readonly class VerificationPolicyProvider
{
    public function __construct(
        private string $issuer = '',
        private string $sanRegex = '',
        private string $publicKeyFingerprint = '',
    ) {}

    public function policy(): ?VerificationPolicy
    {
        if ($this->publicKeyFingerprint !== '') {
            return VerificationPolicy::publicKey($this->publicKeyFingerprint);
        }

        if ($this->issuer !== '' && $this->sanRegex !== '') {
            return VerificationPolicy::keyless($this->issuer, $this->sanRegex);
        }

        return null;
    }

    /**
     * True when verification is half-configured — an issuer without a SAN regex, or vice versa.
     *
     * Silently skipping in that state is how a deploy ends up unverified while someone believes it
     * is gated, so the check surfaces it as a failure rather than treating it as "not configured".
     */
    public function isPartiallyConfigured(): bool
    {
        if ($this->publicKeyFingerprint !== '') {
            return false;
        }

        return ($this->issuer === '') !== ($this->sanRegex === '');
    }
}
