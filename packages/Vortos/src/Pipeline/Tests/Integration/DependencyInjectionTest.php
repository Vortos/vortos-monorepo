<?php

declare(strict_types=1);

namespace Vortos\Pipeline\Tests\Integration;

use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBag;
use Vortos\Pipeline\DependencyInjection\PipelineExtension;
use Vortos\Pipeline\DependencyInjection\PipelinePackage;
use Vortos\Pipeline\Console\PipelineGenerateCommand;
use Vortos\Pipeline\Console\PipelineVerifyCommand;
use Vortos\Pipeline\Driver\GitHubActions\GitHubActionsEmitter;
use Vortos\Pipeline\Emitter\PipelineEmitterInterface;
use Vortos\Pipeline\Emitter\PipelineEmitterRegistry;

final class DependencyInjectionTest extends TestCase
{
    public function test_pipeline_package_boots(): void
    {
        $container = $this->buildContainer();
        $this->assertTrue($container->has(PipelineEmitterRegistry::class));
    }

    public function test_registry_resolves_github_emitter(): void
    {
        $container = $this->buildContainer();

        $registry = $container->get(PipelineEmitterRegistry::class);
        $this->assertInstanceOf(PipelineEmitterRegistry::class, $registry);

        $emitter = $registry->emitter('github');
        $this->assertInstanceOf(PipelineEmitterInterface::class, $emitter);
        $this->assertInstanceOf(GitHubActionsEmitter::class, $emitter);
    }

    public function test_github_emitter_has_capabilities(): void
    {
        $container = $this->buildContainer();

        $registry = $container->get(PipelineEmitterRegistry::class);
        $emitter = $registry->emitter('github');

        $descriptor = $emitter->capabilities();
        $this->assertTrue($descriptor->supports('github_actions'));
        $this->assertFalse($descriptor->supports('gitlab_ci'));
    }

    public function test_commands_are_tagged(): void
    {
        $container = new ContainerBuilder(new ParameterBag([
            'kernel.project_dir' => sys_get_temp_dir(),
        ]));

        $package = new PipelinePackage();
        $extension = $package->getContainerExtension();
        $this->assertNotNull($extension);

        $extension->load([], $container);
        $package->build($container);

        $commandServiceIds = array_keys($container->findTaggedServiceIds('console.command'));

        $this->assertContains(
            PipelineGenerateCommand::class,
            $commandServiceIds,
            'pipeline:generate command must be tagged console.command',
        );

        $this->assertContains(
            PipelineVerifyCommand::class,
            $commandServiceIds,
            'pipeline:verify command must be tagged console.command',
        );
    }

    private function buildContainer(bool $compile = true): ContainerBuilder
    {
        $container = new ContainerBuilder(new ParameterBag([
            'kernel.project_dir' => sys_get_temp_dir(),
        ]));

        $package = new PipelinePackage();

        $extension = $package->getContainerExtension();
        $this->assertNotNull($extension);
        $extension->load([], $container);

        $package->build($container);

        if ($compile) {
            $container->getDefinition(PipelineEmitterRegistry::class)->setPublic(true);
            $container->compile();
        }

        return $container;
    }
}
