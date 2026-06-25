<?php

declare(strict_types=1);

namespace Vortos\Deploy\Tests\Unit\DependencyInjection;

use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\Argument\TaggedIteratorArgument;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Vortos\Deploy\Console\DeployCommand;
use Vortos\Deploy\Console\DoctorCommand;
use Vortos\Deploy\Console\RollbackCommand;
use Vortos\Deploy\Definition\LayeredDefinitionResolver;
use Vortos\Deploy\DependencyInjection\DeployExtension;
use Vortos\Deploy\Plan\DeployPreflightStateBuilder;
use Vortos\Deploy\Preflight\Check\CapabilityDescriptorCheck;
use Vortos\Deploy\Preflight\Check\CredentialCheck;
use Vortos\Deploy\Preflight\Check\DriverSetCheck;
use Vortos\Deploy\Preflight\Check\SchemaCompatibilityCheck;
use Vortos\Deploy\Preflight\Check\TargetArchCheck;
use Vortos\Deploy\Preflight\DeployDoctor;
use Vortos\Deploy\Preflight\PreflightContextFactory;
use Vortos\Deploy\Rollback\RollbackGuard;
use Vortos\Deploy\Runner\DeployRunner;
use Vortos\Deploy\Runner\RollbackRunner;
use Vortos\Release\ReadModel\ManifestReadModelInterface;

final class DeployDoctorWiringTest extends TestCase
{
    private const TAG = 'vortos.deploy.preflight_check';

    public function test_doctor_and_all_five_checks_are_registered_and_tagged(): void
    {
        $container = $this->loaded();

        self::assertTrue($container->hasDefinition(DeployDoctor::class));
        self::assertTrue($container->hasDefinition(LayeredDefinitionResolver::class));

        $checks = [DriverSetCheck::class, CapabilityDescriptorCheck::class, CredentialCheck::class, TargetArchCheck::class, SchemaCompatibilityCheck::class];
        foreach ($checks as $check) {
            self::assertTrue($container->hasDefinition($check), $check . ' must be registered');
            self::assertArrayHasKey(self::TAG, $container->getDefinition($check)->getTags(), $check . ' must be tagged');
        }

        $arg = $container->getDefinition(DeployDoctor::class)->getArgument('$checks');
        self::assertInstanceOf(TaggedIteratorArgument::class, $arg);
    }

    public function test_runners_and_commands_absent_without_context_deps(): void
    {
        $container = $this->loaded();

        self::assertFalse($container->hasDefinition(PreflightContextFactory::class));
        self::assertFalse($container->hasDefinition(DeployRunner::class));
        self::assertFalse($container->hasDefinition(RollbackRunner::class));
        self::assertFalse($container->hasDefinition(DoctorCommand::class));
        self::assertFalse($container->hasDefinition(DeployCommand::class));
        self::assertFalse($container->hasDefinition(RollbackCommand::class));
    }

    public function test_runners_and_commands_present_with_context_deps(): void
    {
        $container = new ContainerBuilder();
        // Pre-register the migration/release stack the runners depend on.
        $container->setDefinition(DeployPreflightStateBuilder::class, new Definition(\stdClass::class));
        $container->setDefinition(ManifestReadModelInterface::class, new Definition(\stdClass::class));
        $container->setDefinition(RollbackGuard::class, new Definition(\stdClass::class));

        (new DeployExtension())->load([], $container);

        self::assertTrue($container->hasDefinition(PreflightContextFactory::class));
        self::assertTrue($container->hasDefinition(DeployRunner::class));
        self::assertTrue($container->hasDefinition(RollbackRunner::class));

        foreach ([DoctorCommand::class, DeployCommand::class, RollbackCommand::class] as $command) {
            self::assertTrue($container->hasDefinition($command), $command . ' must be registered');
            self::assertArrayHasKey('console.command', $container->getDefinition($command)->getTags());
        }
    }

    private function loaded(): ContainerBuilder
    {
        $container = new ContainerBuilder();
        (new DeployExtension())->load([], $container);

        return $container;
    }
}
