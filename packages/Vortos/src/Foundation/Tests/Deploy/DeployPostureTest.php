<?php

declare(strict_types=1);

namespace Vortos\Foundation\Tests\Deploy;

use PHPUnit\Framework\TestCase;
use Vortos\Foundation\Deploy\DeployPosture;

final class DeployPostureTest extends TestCase
{
    public function test_emits_oidc_only_for_ssh_ca_oidc(): void
    {
        $this->assertTrue(DeployPosture::SshCaOidc->emitsOidc());
        $this->assertFalse(DeployPosture::SshKey->emitsOidc());
        $this->assertFalse(DeployPosture::PullAgent->emitsOidc());
    }

    public function test_try_from_credential_maps_built_ins(): void
    {
        $this->assertSame(DeployPosture::SshKey, DeployPosture::tryFromCredential('ssh-key'));
        $this->assertSame(DeployPosture::SshCaOidc, DeployPosture::tryFromCredential('ssh-ca-oidc'));
        $this->assertSame(DeployPosture::PullAgent, DeployPosture::tryFromCredential('pull-agent'));
    }

    public function test_try_from_credential_returns_null_for_custom(): void
    {
        $this->assertNull(DeployPosture::tryFromCredential('my-custom-provider'));
        $this->assertNull(DeployPosture::tryFromCredential(''));
    }

    public function test_case_values_match_deploy_credential_keys(): void
    {
        $this->assertSame('ssh-key', DeployPosture::SshKey->value);
        $this->assertSame('ssh-ca-oidc', DeployPosture::SshCaOidc->value);
        $this->assertSame('pull-agent', DeployPosture::PullAgent->value);
    }
}
