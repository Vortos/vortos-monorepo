<?php

declare(strict_types=1);

namespace Vortos\Security\Secrets;

use Vortos\Security\Contract\SecretsInterface;

/**
 * Reads secrets from environment variables.
 *
 * Default provider — zero overhead, no dependencies, no network calls.
 * Use this in development and in environments where secrets are injected
 * via the process environment (Docker secrets, Kubernetes envFrom, etc.).
 */
final class EnvSecretsProvider implements SecretsInterface
{
    public function get(string $key): string
    {
        return $_ENV[$key] ?? getenv($key) ?: '';
    }
}
