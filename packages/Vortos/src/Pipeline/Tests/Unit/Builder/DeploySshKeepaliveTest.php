<?php

declare(strict_types=1);

namespace Vortos\Pipeline\Tests\Unit\Builder;

use PHPUnit\Framework\TestCase;
use Vortos\Pipeline\Builder\PipelineBuilder;
use Vortos\Pipeline\Builder\StageGate;
use Symfony\Component\DependencyInjection\ServiceLocator;
use Vortos\Pipeline\Definition\PipelineDefinition;
use Vortos\Pipeline\Registry\CiRegistryLoginProviderRegistry;
use Vortos\Pipeline\Driver\Registry\GhcrCiLoginProvider;
use Vortos\Pipeline\Model\CommandStep;

/**
 * The deploy ssh session must be kept alive.
 *
 * A deploy runs long, mostly-silent steps over ONE ssh session — migrations, provisioning, the
 * health gate, cache warmup. Cache warmup alone can sit quiet for minutes. Without a keepalive an
 * idle hop between the runner and the box drops the connection, ssh exits 255 with
 * "client_loop: send disconnect: Broken pipe", and a deploy that was doing nothing wrong fails.
 *
 * Observed in production: the transport died after roughly four minutes of silence during warmup.
 * The cutover had in fact already happened, so the run reported failure while the box had moved —
 * the transport cannot tell you which side of the work it died on, and someone has to go and look.
 */
final class DeploySshKeepaliveTest extends TestCase
{
    private function deployScript(): string
    {
        // The ssh path is the deploy-in-image mode, which is selected by having an image
        // repository (hasBuildStage()). Without one the pipeline deploys from the runner and never
        // opens an ssh session at all.
        $definition = new PipelineDefinition(
            imageRepository: 'ghcr.io/acme/app',
            nativeRunnerLabel: 'ubuntu-24.04-arm',
        );

        $registry = new CiRegistryLoginProviderRegistry(new ServiceLocator([
            'ghcr' => static fn () => new GhcrCiLoginProvider(),
        ]));

        $pipeline = (new PipelineBuilder(new StageGate(), $registry))->build($definition);

        $found = '';
        foreach ($pipeline->stages as $stage) {
            foreach ($stage->steps as $step) {
                if ($step instanceof CommandStep && str_contains($step->run, 'ssh -i ~/.ssh/vortos_deploy')) {
                    $found .= $step->run . "\n";
                }
            }
        }

        return $found;
    }

    public function test_the_deploy_ssh_command_sets_a_keepalive(): void
    {
        $script = $this->deployScript();

        self::assertNotSame('', $script, 'no ssh deploy invocation found to assert against');
        self::assertStringContainsString('-o ServerAliveInterval=', $script);
        self::assertStringContainsString('-o ServerAliveCountMax=', $script);
    }

    /**
     * Keepalive must not weaken host verification — the deploy key can push to production, so the
     * host identity check stays strict.
     */
    public function test_it_still_verifies_the_host_key(): void
    {
        $script = $this->deployScript();

        self::assertStringContainsString('-o StrictHostKeyChecking=yes', $script);
        self::assertStringContainsString('-o UserKnownHostsFile=', $script);
    }
}
