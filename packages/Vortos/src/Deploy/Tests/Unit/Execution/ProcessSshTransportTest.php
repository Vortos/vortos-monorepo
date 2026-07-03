<?php

declare(strict_types=1);

namespace Vortos\Deploy\Tests\Unit\Execution;

use PHPUnit\Framework\TestCase;
use Vortos\Deploy\Execution\CommandResult;
use Vortos\Deploy\Execution\ProcessSshTransport;
use Vortos\Deploy\Execution\RemoteCommand;
use Vortos\Deploy\Execution\SshConnectionConfig;
use Vortos\Deploy\Tests\Fixtures\FakeCommandRunner;

final class ProcessSshTransportTest extends TestCase
{
    private function config(?string $controlPath = null): SshConnectionConfig
    {
        return new SshConnectionConfig(
            host: 'vps.example.com',
            user: 'deploy',
            identityFile: '/run/keys/id_ed25519',
            knownHostsFile: '/run/keys/known_hosts',
            port: 2222,
            controlPath: $controlPath,
        );
    }

    public function test_run_builds_strict_host_key_ssh_argv(): void
    {
        $runner = new FakeCommandRunner();
        $transport = new ProcessSshTransport($runner, $this->config());

        $transport->run(new RemoteCommand(['docker', 'compose', 'up', '-d']));

        $argv = $runner->calls[0]['argv'];

        $this->assertSame('ssh', $argv[0]);
        // Host-key verification is mandatory and strict — never disabled.
        $this->assertContainsSubsequence(['-o', 'StrictHostKeyChecking=yes'], $argv);
        $this->assertContainsSubsequence(['-o', 'UserKnownHostsFile=/run/keys/known_hosts'], $argv);
        $this->assertContainsSubsequence(['-i', '/run/keys/id_ed25519'], $argv);
        $this->assertContainsSubsequence(['-p', '2222'], $argv);
        $this->assertContains('deploy@vps.example.com', $argv);
        // The remote command is passed through sh -c, safely quoted.
        $this->assertContains('sh', $argv);
        $this->assertContains('-c', $argv);
        $this->assertStringContainsString("'docker' 'compose' 'up' '-d'", end($argv));
    }

    public function test_run_never_disables_host_key_checking(): void
    {
        $runner = new FakeCommandRunner();
        $transport = new ProcessSshTransport($runner, $this->config());

        $transport->run(new RemoteCommand(['true']));

        $joined = implode(' ', $runner->calls[0]['argv']);
        $this->assertStringNotContainsString('StrictHostKeyChecking=no', $joined);
        $this->assertStringNotContainsString('UserKnownHostsFile=/dev/null', $joined);
    }

    public function test_run_forwards_stdin_and_working_dir(): void
    {
        $runner = new FakeCommandRunner();
        $transport = new ProcessSshTransport($runner, $this->config());

        $transport->run(new RemoteCommand(['docker', 'login'], stdin: 'secret-token', workingDir: '/srv/app'));

        $this->assertSame('secret-token', $runner->calls[0]['stdin']);
        $this->assertStringContainsString("cd '/srv/app' &&", end($runner->calls[0]['argv']));
    }

    public function test_copy_uses_scp_with_uppercase_port_then_chmods(): void
    {
        $runner = new FakeCommandRunner();
        $transport = new ProcessSshTransport($runner, $this->config());

        $transport->copy('/tmp/local.json', '/etc/caddy/upstream.json', '0640');

        $scp = $runner->calls[0]['argv'];
        $this->assertSame('scp', $scp[0]);
        // scp uses -P (uppercase) for the port, not -p.
        $this->assertContainsSubsequence(['-P', '2222'], $scp);
        $this->assertNotContains('-p', $scp);
        $this->assertContains('/tmp/local.json', $scp);
        $this->assertContains('deploy@vps.example.com:/etc/caddy/upstream.json', $scp);

        // Second call chmods the uploaded file over ssh.
        $chmod = end($runner->calls[1]['argv']);
        $this->assertStringContainsString("'chmod' '0640' '/etc/caddy/upstream.json'", $chmod);
    }

    public function test_open_local_forward_requires_control_path(): void
    {
        $runner = new FakeCommandRunner();
        $transport = new ProcessSshTransport($runner, $this->config(controlPath: null));

        $this->expectException(\RuntimeException::class);
        $transport->openLocalForward(2019);
    }

    public function test_open_local_forward_requests_forward_on_master(): void
    {
        $runner = new FakeCommandRunner();
        $transport = new ProcessSshTransport($runner, $this->config(controlPath: '/run/cm/sock'));

        $localPort = $transport->openLocalForward(2019);

        $this->assertGreaterThan(0, $localPort);
        // The last call requests the forward via the control master.
        $forwardCall = end($runner->calls)['argv'];
        $this->assertContainsSubsequence(['-O', 'forward'], $forwardCall);
        $this->assertContainsSubsequence(['-L', sprintf('%d:127.0.0.1:2019', $localPort)], $forwardCall);
    }

    /**
     * @param list<string> $needle
     * @param list<string> $haystack
     */
    private function assertContainsSubsequence(array $needle, array $haystack): void
    {
        $n = count($needle);
        for ($i = 0; $i + $n <= count($haystack); $i++) {
            if (array_slice($haystack, $i, $n) === $needle) {
                $this->addToAssertionCount(1);

                return;
            }
        }

        $this->fail(sprintf('Subsequence [%s] not found in [%s].', implode(', ', $needle), implode(', ', $haystack)));
    }
}
