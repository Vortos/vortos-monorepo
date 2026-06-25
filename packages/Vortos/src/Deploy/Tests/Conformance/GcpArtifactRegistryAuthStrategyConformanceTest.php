<?php

declare(strict_types=1);

namespace Vortos\Deploy\Tests\Conformance;

use Vortos\Deploy\Driver\Registry\Auth\GcpArtifactRegistryAuthStrategy;
use Vortos\Deploy\Registry\Auth\RegistryAuthStrategyInterface;
use Vortos\Deploy\Registry\BasicAuthCredential;
use Vortos\Deploy\Registry\GcpServiceAccountCredential;
use Vortos\Deploy\Registry\RegistryCredential;
use Vortos\Deploy\Testing\RegistryAuthStrategyConformanceTestCase;
use Vortos\Secrets\Value\SecretValue;

final class GcpArtifactRegistryAuthStrategyConformanceTest extends RegistryAuthStrategyConformanceTestCase
{
    protected function createStrategy(): RegistryAuthStrategyInterface
    {
        return new GcpArtifactRegistryAuthStrategy();
    }

    protected function createValidCredential(): RegistryCredential
    {
        return new GcpServiceAccountCredential(
            'europe-west4-docker.pkg.dev',
            SecretValue::fromString('{"type":"service_account"}'),
        );
    }

    protected function createForeignCredential(): ?RegistryCredential
    {
        return new BasicAuthCredential('user', SecretValue::fromString('pass'));
    }

    protected function expectedKey(): string
    {
        return 'gcp-artifact-registry';
    }
}
