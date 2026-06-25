<?php

declare(strict_types=1);

namespace Vortos\Deploy\Tests\Conformance;

use Vortos\Deploy\Driver\Registry\Auth\GhcrAuthStrategy;
use Vortos\Deploy\Registry\Auth\RegistryAuthStrategyInterface;
use Vortos\Deploy\Registry\BasicAuthCredential;
use Vortos\Deploy\Registry\PatTokenCredential;
use Vortos\Deploy\Registry\RegistryCredential;
use Vortos\Deploy\Testing\RegistryAuthStrategyConformanceTestCase;
use Vortos\Secrets\Value\SecretValue;

final class GhcrAuthStrategyConformanceTest extends RegistryAuthStrategyConformanceTestCase
{
    protected function createStrategy(): RegistryAuthStrategyInterface
    {
        return new GhcrAuthStrategy();
    }

    protected function createValidCredential(): RegistryCredential
    {
        return new PatTokenCredential('github-actor', SecretValue::fromString('ghp_test_token'));
    }

    protected function createForeignCredential(): ?RegistryCredential
    {
        return new BasicAuthCredential('user', SecretValue::fromString('pass'));
    }

    protected function expectedKey(): string
    {
        return 'ghcr';
    }
}
