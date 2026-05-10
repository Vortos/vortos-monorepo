<?php

declare(strict_types=1);

namespace Vortos\Security\Contract;

interface SecretsInterface
{
    /**
     * Retrieves a secret by key.
     *
     * @param string $key The secret name/path as understood by the active provider.
     *                    - EnvSecretsProvider:   environment variable name (e.g. 'DB_PASSWORD')
     *                    - VaultSecretsProvider:  Vault path (e.g. 'secret/data/myapp/db#password')
     *                    - AwsSsmSecretsProvider: parameter path with prefix applied
     *
     * @return string The secret value, or empty string if not found.
     */
    public function get(string $key): string;
}
