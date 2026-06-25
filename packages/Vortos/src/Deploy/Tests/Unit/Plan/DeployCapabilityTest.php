<?php

declare(strict_types=1);

namespace Vortos\Deploy\Tests\Unit\Plan;

use PHPUnit\Framework\TestCase;
use Vortos\Deploy\Credential\CredentialCapability;
use Vortos\Deploy\Registry\RegistryCapability;
use Vortos\Deploy\Target\DeployCapability;

final class DeployCapabilityTest extends TestCase
{
    public function test_deploy_capability_keys_are_lower_snake(): void
    {
        foreach (DeployCapability::cases() as $case) {
            self::assertMatchesRegularExpression('/^[a-z][a-z0-9_]*$/', $case->key());
        }
    }

    public function test_registry_capability_keys_are_lower_snake(): void
    {
        foreach (RegistryCapability::cases() as $case) {
            self::assertMatchesRegularExpression('/^[a-z][a-z0-9_]*$/', $case->key());
        }
    }

    public function test_credential_capability_keys_are_lower_snake(): void
    {
        foreach (CredentialCapability::cases() as $case) {
            self::assertMatchesRegularExpression('/^[a-z][a-z0-9_]*$/', $case->key());
        }
    }
}
