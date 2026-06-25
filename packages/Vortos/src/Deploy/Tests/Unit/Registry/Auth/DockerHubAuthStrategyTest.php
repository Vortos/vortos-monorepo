<?php

declare(strict_types=1);

namespace Vortos\Deploy\Tests\Unit\Registry\Auth;

use PHPUnit\Framework\TestCase;
use Vortos\Deploy\Driver\Registry\Auth\DockerHubAuthStrategy;
use Vortos\Deploy\Registry\BasicAuthCredential;
use Vortos\Deploy\Registry\PatTokenCredential;
use Vortos\Deploy\Tests\Fixtures\FakeCommandRunner;
use Vortos\Secrets\Value\SecretValue;

final class DockerHubAuthStrategyTest extends TestCase
{
    private DockerHubAuthStrategy $strategy;
    private FakeCommandRunner $runner;

    protected function setUp(): void
    {
        $this->strategy = new DockerHubAuthStrategy();
        $this->runner = new FakeCommandRunner();
    }

    public function test_login_targets_docker_io(): void
    {
        $credential = new BasicAuthCredential('myuser', SecretValue::fromString('pass'));
        $this->strategy->login($this->runner, $credential);

        $this->assertSame('docker.io', $this->runner->calls[0]['argv'][2]);
    }

    public function test_login_uses_provided_username(): void
    {
        $credential = new BasicAuthCredential('dockeruser', SecretValue::fromString('pass'));
        $this->strategy->login($this->runner, $credential);

        $argv = $this->runner->calls[0]['argv'];
        $idx = array_search('--username', $argv, true);
        $this->assertSame('dockeruser', $argv[$idx + 1]);
    }

    public function test_login_passes_password_via_stdin(): void
    {
        $credential = new BasicAuthCredential('user', SecretValue::fromString('dckr_pat_secret'));
        $this->strategy->login($this->runner, $credential);

        $this->assertSame('dckr_pat_secret', $this->runner->calls[0]['stdin']);
    }

    public function test_password_never_in_argv(): void
    {
        $password = 'dckr_pat_super_secret';
        $credential = new BasicAuthCredential('user', SecretValue::fromString($password));
        $this->strategy->login($this->runner, $credential);

        foreach ($this->runner->calls[0]['argv'] as $arg) {
            $this->assertStringNotContainsString($password, $arg);
        }
    }

    public function test_does_not_support_ghcr_credential(): void
    {
        $this->assertFalse(
            $this->strategy->supports(new PatTokenCredential('actor', SecretValue::fromString('token'))),
        );
    }

    public function test_supports_basic_auth_credential(): void
    {
        $this->assertTrue(
            $this->strategy->supports(new BasicAuthCredential('u', SecretValue::fromString('p'))),
        );
    }

    public function test_login_throws_for_wrong_credential_type(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->strategy->login(
            $this->runner,
            new PatTokenCredential('actor', SecretValue::fromString('token')),
        );
    }
}
