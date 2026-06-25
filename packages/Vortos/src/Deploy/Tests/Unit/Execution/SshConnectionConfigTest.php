<?php

declare(strict_types=1);

namespace Vortos\Deploy\Tests\Unit\Execution;

use PHPUnit\Framework\TestCase;
use Vortos\Deploy\Execution\SshConnectionConfig;

final class SshConnectionConfigTest extends TestCase
{
    public function test_valid_config(): void
    {
        $config = new SshConnectionConfig(
            host: 'deploy.example.com',
            user: 'deploy',
            identityFile: '/home/deploy/.ssh/id_ed25519',
            knownHostsFile: '/home/deploy/.ssh/known_hosts',
        );

        $this->assertSame('deploy.example.com', $config->host);
        $this->assertSame(22, $config->port);
        $this->assertSame('deploy@deploy.example.com', $config->destination());
    }

    public function test_rejects_empty_host(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new SshConnectionConfig('', 'deploy', '/key', '/known_hosts');
    }

    public function test_rejects_empty_user(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new SshConnectionConfig('host', '', '/key', '/known_hosts');
    }

    public function test_rejects_empty_identity_file(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new SshConnectionConfig('host', 'deploy', '', '/known_hosts');
    }

    public function test_rejects_empty_known_hosts(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('host-key verification is mandatory');
        new SshConnectionConfig('host', 'deploy', '/key', '');
    }

    public function test_rejects_invalid_port(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new SshConnectionConfig('host', 'deploy', '/key', '/known_hosts', 0);
    }

    public function test_ssh_options_include_strict_host_key(): void
    {
        $config = new SshConnectionConfig('host', 'deploy', '/key', '/known_hosts');
        $options = $config->toSshOptions();

        $this->assertContains('StrictHostKeyChecking=yes', $options);
    }

    public function test_ssh_options_include_control_master_when_set(): void
    {
        $config = new SshConnectionConfig('host', 'deploy', '/key', '/known_hosts', controlPath: '/tmp/ssh-%h');
        $options = $config->toSshOptions();

        $this->assertContains('ControlMaster=auto', $options);
        $this->assertContains('ControlPersist=60', $options);
    }

    public function test_custom_port(): void
    {
        $config = new SshConnectionConfig('host', 'deploy', '/key', '/known_hosts', 2222);
        $options = $config->toSshOptions();

        $this->assertContains('2222', $options);
    }
}
