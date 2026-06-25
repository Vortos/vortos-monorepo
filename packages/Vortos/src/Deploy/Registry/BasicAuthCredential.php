<?php

declare(strict_types=1);

namespace Vortos\Deploy\Registry;

use Vortos\Secrets\Value\SecretValue;

/**
 * Username + password credential for registries that use standard basic auth.
 * Covers Docker Hub, Azure ACR, Gitea, and similar.
 */
final class BasicAuthCredential extends RegistryCredential
{
    public function __construct(
        public readonly string $username,
        public readonly SecretValue $password,
    ) {
        if ($username === '') {
            throw new \InvalidArgumentException('Registry username must be non-empty.');
        }
    }
}
