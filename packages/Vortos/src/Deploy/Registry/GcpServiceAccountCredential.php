<?php

declare(strict_types=1);

namespace Vortos\Deploy\Registry;

use Vortos\Secrets\Value\SecretValue;

/**
 * GCP service account JSON credential for Artifact Registry.
 *
 * The registryHost is the regional AR hostname, e.g. "europe-west4-docker.pkg.dev".
 * docker/login-action uses _json_key as the username and the JSON as the password.
 */
final class GcpServiceAccountCredential extends RegistryCredential
{
    public function __construct(
        public readonly string $registryHost,
        public readonly SecretValue $serviceAccountJson,
    ) {
        if ($registryHost === '') {
            throw new \InvalidArgumentException('GCP registry host must be non-empty.');
        }
    }
}
