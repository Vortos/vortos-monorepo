<?php

declare(strict_types=1);

namespace Vortos\Deploy\Tests\Unit\Execution;

use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ServiceLocator;
use Vortos\Deploy\Credential\CredentialProviderRegistry;
use Vortos\Deploy\Credential\SshKeyCredentialProvider;
use Vortos\Deploy\Definition\DeploymentDefinition;
use Vortos\Deploy\Definition\EnvironmentName;
use Vortos\Deploy\Execution\DeployConnectionContext;
use Vortos\Deploy\Execution\SshConnectionActivator;
use Vortos\Deploy\Execution\SshConnectionSettings;
use Vortos\Deploy\Tests\Fixtures\FakeSecretsProvider;

final class SshConnectionActivatorTest extends TestCase
{
    private function activator(DeployConnectionContext $context): SshConnectionActivator
    {
        $secrets = new FakeSecretsProvider();
        $secrets->setSecret('deploy_ssh_private_key', 'PRIVATE-KEY');
        $secrets->setSecret('deploy_known_hosts', 'vps.example.com ssh-ed25519 AAAA');

        $registry = new CredentialProviderRegistry(new ServiceLocator([
            'ssh-key' => static fn () => new SshKeyCredentialProvider($secrets),
        ]));

        return new SshConnectionActivator(
            $registry,
            $context,
            new SshConnectionSettings('vps.example.com', 'deploy', 2222),
        );
    }

    private function definition(): DeploymentDefinition
    {
        return DeploymentDefinition::build(credential: 'ssh-key');
    }

    public function test_connection_is_active_during_work_and_torn_down_after(): void
    {
        $context = new DeployConnectionContext();
        $activator = $this->activator($context);

        $this->assertFalse($context->isActive());

        $seen = $activator->withConnection($this->definition(), new EnvironmentName('production'), function () use ($context): array {
            $this->assertTrue($context->isActive(), 'connection must be active inside the work closure');
            $config = $context->config();

            return [
                'host' => $config->host,
                'user' => $config->user,
                'port' => $config->port,
                'identity' => $config->identityFile,
                'knownHosts' => $config->knownHostsFile,
            ];
        });

        // Settings threaded through into the live config.
        $this->assertSame('vps.example.com', $seen['host']);
        $this->assertSame('deploy', $seen['user']);
        $this->assertSame(2222, $seen['port']);

        // Files existed during the work and are materialized with the real key + known_hosts.
        $this->assertNotSame('', $seen['identity']);
        $this->assertNotSame('', $seen['knownHosts']);

        // Torn down afterwards: context deactivated and ephemeral files unlinked.
        $this->assertFalse($context->isActive());
        $this->assertFileDoesNotExist($seen['identity']);
        $this->assertFileDoesNotExist($seen['knownHosts']);
    }

    public function test_materialized_key_file_holds_the_secret_and_is_0600(): void
    {
        $context = new DeployConnectionContext();
        $activator = $this->activator($context);

        $activator->withConnection($this->definition(), new EnvironmentName('production'), function () use ($context): void {
            $path = $context->config()->identityFile;
            $this->assertSame('PRIVATE-KEY', file_get_contents($path));
            $this->assertSame('0600', substr(sprintf('%o', fileperms($path)), -4));
        });
    }

    public function test_deactivates_and_cleans_up_even_when_work_throws(): void
    {
        $context = new DeployConnectionContext();
        $activator = $this->activator($context);
        $captured = null;

        try {
            $activator->withConnection($this->definition(), new EnvironmentName('production'), function () use ($context, &$captured): void {
                $captured = $context->config()->identityFile;
                throw new \RuntimeException('deploy blew up');
            });
            $this->fail('exception should propagate');
        } catch (\RuntimeException $e) {
            $this->assertSame('deploy blew up', $e->getMessage());
        }

        $this->assertFalse($context->isActive());
        $this->assertFileDoesNotExist((string) $captured);
    }
}
