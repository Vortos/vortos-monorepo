<?php

declare(strict_types=1);

namespace Vortos\Deploy\Tests\Conformance;

use Vortos\Deploy\Driver\Registry\Auth\DockerHubAuthStrategy;
use Vortos\Deploy\Registry\Auth\RegistryAuthStrategyInterface;
use Vortos\Deploy\Registry\BasicAuthCredential;
use Vortos\Deploy\Registry\PatTokenCredential;
use Vortos\Deploy\Registry\RegistryCredential;
use Vortos\Deploy\Testing\RegistryAuthStrategyConformanceTestCase;
use Vortos\Secrets\Value\SecretValue;

final class DockerHubAuthStrategyConformanceTest extends RegistryAuthStrategyConformanceTestCase
{
    protected function createStrategy(): RegistryAuthStrategyInterface
    {
        return new DockerHubAuthStrategy();
    }

    protected function createValidCredential(): RegistryCredential
    {
        return new BasicAuthCredential('myuser', SecretValue::fromString('dckr_pat_test_token'));
    }

    protected function createForeignCredential(): ?RegistryCredential
    {
        return new PatTokenCredential('actor', SecretValue::fromString('ghp_token'));
    }

    protected function expectedKey(): string
    {
        return 'docker-hub';
    }
}
