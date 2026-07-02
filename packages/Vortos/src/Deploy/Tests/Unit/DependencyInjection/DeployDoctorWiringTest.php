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
use Vortos\Deploy\DependencyInjection\Compiler\DeployWiringPass;
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
use Vortos\Migration\Schema\MigrationPhaseReaderInterface;
use Vortos\Release\Migration\AppliedMigrationSetReaderInterface;
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

    public function test_commands_always_register_but_runner_services_are_absent_without_context_deps(): void
    {
        $container = $this->loaded();

        // Commands always register (visible in `bin/console list`) and fail loudly at runtime.
        foreach ([DoctorCommand::class, DeployCommand::class, RollbackCommand::class] as $command) {
            self::assertTrue($container->hasDefinition($command), $command . ' must always register');
            self::assertArrayHasKey('console.command', $container->getDefinition($command)->getTags());
        }

        // The runner services have hard cross-package deps and are wired by DeployWiringPass,
        // not load(). Without those deps they must not exist.
        self::assertFalse($container->hasDefinition(PreflightContextFactory::class));
        self::assertFalse($container->hasDefinition(DeployRunner::class));
        self::assertFalse($container->hasDefinition(RollbackRunner::class));
    }

    public function test_wiring_pass_registers_runner_services_when_context_deps_present(): void
    {
        $container = new ContainerBuilder();
        // Pre-register the migration/release stack the wiring pass gates on.
        $container->setDefinition(AppliedMigrationSetReaderInterface::class, new Definition(\stdClass::class));
        $container->setDefinition(ManifestReadModelInterface::class, new Definition(\stdClass::class));
        $container->setDefinition(MigrationPhaseReaderInterface::class, new Definition(\stdClass::class));

        (new DeployExtension())->load([], $container);
        (new DeployWiringPass())->process($container);

        self::assertTrue($container->hasDefinition(RollbackGuard::class));
        self::assertTrue($container->hasDefinition(DeployPreflightStateBuilder::class));
        self::assertTrue($container->hasDefinition(PreflightContextFactory::class));
        self::assertTrue($container->hasDefinition(DeployRunner::class));
        self::assertTrue($container->hasDefinition(RollbackRunner::class));
    }

    private function loaded(): ContainerBuilder
    {
        $container = new ContainerBuilder();
        (new DeployExtension())->load([], $container);

        return $container;
    }
}
