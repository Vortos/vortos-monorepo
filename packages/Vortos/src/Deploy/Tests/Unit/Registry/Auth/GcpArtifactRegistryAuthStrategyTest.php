<?php

declare(strict_types=1);

namespace Vortos\Deploy\Tests\Unit\Registry\Auth;

use PHPUnit\Framework\TestCase;
use Vortos\Deploy\Driver\Registry\Auth\GcpArtifactRegistryAuthStrategy;
use Vortos\Deploy\Registry\BasicAuthCredential;
use Vortos\Deploy\Registry\GcpServiceAccountCredential;
use Vortos\Deploy\Tests\Fixtures\FakeCommandRunner;
use Vortos\Secrets\Value\SecretValue;

final class GcpArtifactRegistryAuthStrategyTest extends TestCase
{
    private GcpArtifactRegistryAuthStrategy $strategy;
    private FakeCommandRunner $runner;

    protected function setUp(): void
    {
        $this->strategy = new GcpArtifactRegistryAuthStrategy();
        $this->runner = new FakeCommandRunner();
    }

    public function test_login_targets_configured_host(): void
    {
        $credential = new GcpServiceAccountCredential(
            'europe-west4-docker.pkg.dev',
            SecretValue::fromString('{}'),
        );
        $this->strategy->login($this->runner, $credential);

        $this->assertSame('europe-west4-docker.pkg.dev', $this->runner->calls[0]['argv'][2]);
    }

    public function test_login_uses_json_key_username(): void
    {
        $credential = new GcpServiceAccountCredential(
            'us-central1-docker.pkg.dev',
            SecretValue::fromString('{}'),
        );
        $this->strategy->login($this->runner, $credential);

        $argv = $this->runner->calls[0]['argv'];
        $idx = array_search('--username', $argv, true);
        $this->assertSame('_json_key', $argv[$idx + 1]);
    }

    public function test_login_passes_json_via_stdin(): void
    {
        $json = '{"type":"service_account","project_id":"my-project"}';
        $credential = new GcpServiceAccountCredential(
            'europe-west4-docker.pkg.dev',
            SecretValue::fromString($json),
        );
        $this->strategy->login($this->runner, $credential);

        $this->assertSame($json, $this->runner->calls[0]['stdin']);
    }

    public function test_sa_json_never_in_argv(): void
    {
        $json = '{"type":"service_account","private_key":"-----BEGIN RSA PRIVATE KEY-----"}';
        $credential = new GcpServiceAccountCredential(
            'europe-west4-docker.pkg.dev',
            SecretValue::fromString($json),
        );
        $this->strategy->login($this->runner, $credential);

        foreach ($this->runner->calls[0]['argv'] as $arg) {
            $this->assertStringNotContainsString('service_account', $arg);
            $this->assertStringNotContainsString('private_key', $arg);
        }
    }

    public function test_does_not_support_basic_auth_credential(): void
    {
        $this->assertFalse(
            $this->strategy->supports(new BasicAuthCredential('u', SecretValue::fromString('p'))),
        );
    }

    public function test_supports_gcp_credential(): void
    {
        $this->assertTrue(
            $this->strategy->supports(new GcpServiceAccountCredential('host', SecretValue::fromString('{}'))),
        );
    }

    public function test_login_throws_for_wrong_credential_type(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->strategy->login(
            $this->runner,
            new BasicAuthCredential('u', SecretValue::fromString('p')),
        );
    }
}
