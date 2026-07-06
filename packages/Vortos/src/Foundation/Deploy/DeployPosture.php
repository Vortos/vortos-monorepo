<?php

declare(strict_types=1);

namespace Vortos\Foundation\Deploy;

/**
 * The first-class deploy authentication postures the framework reasons about — the typed vocabulary
 * shared by the deploy definition (which credential provider signs the connection) and the pipeline
 * definition (whether the emitted CI deploy job uses keyless OIDC or a long-lived key).
 *
 * The deploy side keeps an extensible string `credential` (validated against the runtime
 * CredentialProviderRegistry, so custom providers remain possible); {@see tryFromCredential()} maps
 * the three built-in credential keys onto this enum and returns null for a custom provider. The
 * pipeline side uses this enum directly: CI deploy auth posture is a closed set, and coupling the
 * OIDC default to the actual posture (not to whether an image repository happens to be set) is the
 * whole point — an ssh-key age-KEK deploy must never emit a keyless-OIDC job it cannot satisfy.
 */
enum DeployPosture: string
{
    /** Long-lived SSH key (age-KEK deploy-in-image). Not keyless — no OIDC job. */
    case SshKey = 'ssh-key';

    /** Keyless: an OIDC token is exchanged for a short-lived SSH certificate (zero standing secrets). */
    case SshCaOidc = 'ssh-ca-oidc';

    /** Pull-based delivery: the target reconciles a signed desired-state manifest; no push credential. */
    case PullAgent = 'pull-agent';

    /**
     * Whether an emitted CI deploy job for this posture should request keyless OIDC (id-token exchange).
     * Only the SSH CA OIDC posture is keyless; ssh-key and pull-agent must NOT emit an OIDC deploy job.
     */
    public function emitsOidc(): bool
    {
        return $this === self::SshCaOidc;
    }

    /**
     * Map a deploy `credential` key onto its posture, or null when the credential is a custom provider
     * outside the built-in set (in which case the pipeline oidc default stays conservative — off — unless
     * the app sets oidc explicitly).
     */
    public static function tryFromCredential(string $credential): ?self
    {
        return self::tryFrom($credential);
    }
}
