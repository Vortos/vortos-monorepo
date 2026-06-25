<?php

declare(strict_types=1);

namespace Vortos\Deploy\Tests\Unit\Registry\Auth;

use PHPUnit\Framework\TestCase;
use Vortos\Deploy\Driver\Registry\Auth\GhcrAuthStrategy;
use Vortos\Deploy\Registry\BasicAuthCredential;
use Vortos\Deploy\Registry\PatTokenCredential;
use Vortos\Deploy\Tests\Fixtures\FakeCommandRunner;
use Vortos\Secrets\Value\SecretValue;

final class GhcrAuthStrategyTest extends TestCase
{
    private GhcrAuthStrategy $strategy;
    private FakeCommandRunner $runner;

    protected function setUp(): void
    {
        $this->strategy = new GhcrAuthStrategy();
        $this->runner = new FakeCommandRunner();
    }

    public function test_login_targets_ghcr_io(): void
    {
        $credential = new PatTokenCredential('actor', SecretValue::fromString('token'));
        $this->strategy->login($this->runner, $credential);

        $this->assertSame('docker', $this->runner->calls[0]['argv'][0]);
        $this->assertSame('login', $this->runner->calls[0]['argv'][1]);
        $this->assertSame('ghcr.io', $this->runner->calls[0]['argv'][2]);
    }

    public function test_login_uses_provided_username(): void
    {
        $credential = new PatTokenCredential('my-actor', SecretValue::fromString('token'));
        $this->strategy->login($this->runner, $credential);

        $argv = $this->runner->calls[0]['argv'];
        $usernameIdx = array_search('--username', $argv, true);
        $this->assertNotFalse($usernameIdx);
        $this->assertSame('my-actor', $argv[$usernameIdx + 1]);
    }

    public function test_login_uses_password_stdin_flag(): void
    {
        $credential = new PatTokenCredential('actor', SecretValue::fromString('token'));
        $this->strategy->login($this->runner, $credential);

        $this->assertContains('--password-stdin', $this->runner->calls[0]['argv']);
    }

    public function test_login_passes_token_via_stdin(): void
    {
        $credential = new PatTokenCredential('actor', SecretValue::fromString('ghp_secret'));
        $this->strategy->login($this->runner, $credential);

        $this->assertSame('ghp_secret', $this->runner->calls[0]['stdin']);
    }

    public function test_token_never_in_argv(): void
    {
        $token = 'ghp_super_secret_token';
        $credential = new PatTokenCredential('actor', SecretValue::fromString($token));
        $this->strategy->login($this->runner, $credential);

        foreach ($this->runner->calls[0]['argv'] as $arg) {
            $this->assertStringNotContainsString($token, $arg);
        }
    }

    public function test_does_not_support_basic_auth_credential(): void
    {
        $this->assertFalse(
            $this->strategy->supports(new BasicAuthCredential('u', SecretValue::fromString('p'))),
        );
    }

    public function test_supports_ghcr_credential(): void
    {
        $this->assertTrue(
            $this->strategy->supports(new PatTokenCredential('actor', SecretValue::fromString('token'))),
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
