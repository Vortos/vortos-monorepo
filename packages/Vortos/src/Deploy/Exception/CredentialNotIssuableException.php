<?php

declare(strict_types=1);

namespace Vortos\Deploy\Exception;

/**
 * Thrown by {@see \Vortos\Deploy\Credential\CredentialProviderInterface::assertIssuable()}
 * when a non-mutating preflight proves the provider could not mint in the target
 * environment (missing config, unreachable signer/OIDC, absent backing secret).
 *
 * It carries no credential material — only the reason a mint would fail.
 */
final class CredentialNotIssuableException extends DeployException
{
    public static function forProvider(string $providerKey, string $reason): self
    {
        return new self(sprintf(
            'Credential provider "%s" cannot mint: %s',
            $providerKey,
            $reason,
        ));
    }
}
