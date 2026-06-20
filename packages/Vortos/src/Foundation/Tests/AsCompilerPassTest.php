<?php

declare(strict_types=1);

namespace Vortos\Foundation\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\Compiler\PassConfig;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Vortos\Foundation\DependencyInjection\Compiler\CompilerPassDiscoveryPass;
use Vortos\Foundation\DependencyInjection\Enum\CompilerPassType;

// --- fixtures ---

final class AcpFixtureDefaultPass implements CompilerPassInterface
{
    public function process(ContainerBuilder $container): void {}
}

final class AcpFixtureExplicitPass implements CompilerPassInterface
{
    public function process(ContainerBuilder $container): void {}
}

final class AcpFixtureSecondPass implements CompilerPassInterface
{
    public function process(ContainerBuilder $container): void {}
}

final class AcpFixtureThirdPass implements CompilerPassInterface
{
    public function process(ContainerBuilder $container): void {}
}

final class AcpFixtureNonPass
{
    // Does NOT implement CompilerPassInterface — used for the security gate test.
}

// --- tests ---

final class AsCompilerPassTest extends TestCase
{
    private function container(): ContainerBuilder
    {
        return new ContainerBuilder();
    }

    private function registerTagged(
        ContainerBuilder $container,
        string $class,
        string $type = 'beforeOptimization',
        int $priority = 0,
    ): void {
        $def = new Definition($class);
        $def->addTag('vortos.compiler_pass', ['type' => $type, 'priority' => $priority]);
        $container->setDefinition($class, $def);
    }

    /** @return list<CompilerPassInterface> */
    private function passesForType(ContainerBuilder $container, CompilerPassType $type): array
    {
        $config = $container->getCompiler()->getPassConfig();

        return match ($type) {
            CompilerPassType::BeforeOptimization => $config->getBeforeOptimizationPasses(),
            CompilerPassType::Optimize           => $config->getOptimizationPasses(),
            CompilerPassType::BeforeRemoving     => $config->getBeforeRemovingPasses(),
            CompilerPassType::Remove             => $config->getRemovingPasses(),
            CompilerPassType::AfterRemoving      => $config->getAfterRemovingPasses(),
        };
    }

    private function passClasses(ContainerBuilder $container, CompilerPassType $type): array
    {
        return array_map(get_class(...), $this->passesForType($container, $type));
    }

    // -------------------------------------------------------------------------

    public function test_registers_compiler_pass_with_default_type_and_priority(): void
    {
        $container = $this->container();
        $this->registerTagged($container, AcpFixtureDefaultPass::class);

        (new CompilerPassDiscoveryPass())->process($container);

        $this->assertContains(
            AcpFixtureDefaultPass::class,
            $this->passClasses($container, CompilerPassType::BeforeOptimization),
        );
    }

    public function test_registers_compiler_pass_with_explicit_type_and_priority(): void
    {
        $container = $this->container();
        $this->registerTagged($container, AcpFixtureExplicitPass::class, 'afterRemoving', 42);

        (new CompilerPassDiscoveryPass())->process($container);

        $this->assertContains(
            AcpFixtureExplicitPass::class,
            $this->passClasses($container, CompilerPassType::AfterRemoving),
        );
    }

    public function test_removes_service_definition_after_registration(): void
    {
        $container = $this->container();
        $this->registerTagged($container, AcpFixtureDefaultPass::class);

        (new CompilerPassDiscoveryPass())->process($container);

        $this->assertFalse($container->hasDefinition(AcpFixtureDefaultPass::class));
    }

    public function test_multiple_passes_all_registered(): void
    {
        $container = $this->container();
        $this->registerTagged($container, AcpFixtureSecondPass::class);
        $this->registerTagged($container, AcpFixtureThirdPass::class);

        (new CompilerPassDiscoveryPass())->process($container);

        $classes = $this->passClasses($container, CompilerPassType::BeforeOptimization);
        $this->assertContains(AcpFixtureSecondPass::class, $classes);
        $this->assertContains(AcpFixtureThirdPass::class, $classes);
    }

    public function test_throws_when_class_does_not_implement_compiler_pass_interface(): void
    {
        $container = $this->container();
        $def = new Definition(AcpFixtureNonPass::class);
        $def->addTag('vortos.compiler_pass', ['type' => 'beforeOptimization', 'priority' => 0]);
        $container->setDefinition(AcpFixtureNonPass::class, $def);

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessageMatches('/does not implement/i');

        (new CompilerPassDiscoveryPass())->process($container);
    }

    public function test_priority_is_respected(): void
    {
        $container = $this->container();
        // Register in the AfterRemoving bucket so we can check a bucket distinct
        // from the default and easily assert the pass landed in the right place.
        $this->registerTagged($container, AcpFixtureExplicitPass::class, 'afterRemoving', 99);

        (new CompilerPassDiscoveryPass())->process($container);

        $afterRemoving       = $this->passClasses($container, CompilerPassType::AfterRemoving);
        $beforeOptimization  = $this->passClasses($container, CompilerPassType::BeforeOptimization);

        $this->assertContains(AcpFixtureExplicitPass::class, $afterRemoving);
        $this->assertNotContains(AcpFixtureExplicitPass::class, $beforeOptimization);
    }

    /** @return array<string, array{CompilerPassType}> */
    public static function provideAllCompilerPassTypes(): array
    {
        return array_combine(
            array_map(fn(CompilerPassType $t) => $t->name, CompilerPassType::cases()),
            array_map(fn(CompilerPassType $t) => [$t], CompilerPassType::cases()),
        );
    }

    #[DataProvider('provideAllCompilerPassTypes')]
    public function test_type_maps_correctly_for_all_enum_cases(CompilerPassType $type): void
    {
        $container = $this->container();
        $this->registerTagged($container, AcpFixtureDefaultPass::class, $type->value, 0);

        (new CompilerPassDiscoveryPass())->process($container);

        $this->assertContains(
            AcpFixtureDefaultPass::class,
            $this->passClasses($container, $type),
            sprintf('Pass was not found in the %s bucket after discovery.', $type->name),
        );
    }

    public function test_no_tag_means_not_registered(): void
    {
        $container = $this->container();

        // Register without the tag — should be completely ignored.
        $def = new Definition(AcpFixtureDefaultPass::class);
        $container->setDefinition(AcpFixtureDefaultPass::class, $def);

        (new CompilerPassDiscoveryPass())->process($container);

        // Definition untouched, pass not promoted.
        $this->assertTrue($container->hasDefinition(AcpFixtureDefaultPass::class));

        $allPassClasses = array_map(
            get_class(...),
            $container->getCompiler()->getPassConfig()->getPasses(),
        );
        $this->assertNotContains(AcpFixtureDefaultPass::class, $allPassClasses);
    }
}
