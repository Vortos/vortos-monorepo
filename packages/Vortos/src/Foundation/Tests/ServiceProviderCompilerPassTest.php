<?php

declare(strict_types=1);

namespace Vortos\Foundation\Tests;

use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Reference;
use Vortos\Foundation\DependencyInjection\Attribute\InjectService;
use Vortos\Foundation\DependencyInjection\Attribute\Provides;
use Vortos\Foundation\DependencyInjection\Attribute\ServiceProvider;
use Vortos\Foundation\DependencyInjection\Attribute\Value;
use Vortos\Foundation\DependencyInjection\Compiler\ServiceProviderCompilerPass;

// --- domain fixtures ---

interface SpcDatabaseInterface {}
final class SpcDatabase implements SpcDatabaseInterface {}

interface SpcCacheInterface {}
final class SpcCache implements SpcCacheInterface {}

interface SpcRepositoryInterface {}
final class SpcRepository implements SpcRepositoryInterface {}

interface SpcServiceInterface {}
final class SpcService implements SpcServiceInterface {}

interface SpcAlphaInterface {}
final class SpcAlpha implements SpcAlphaInterface {}

interface SpcBetaInterface {}
final class SpcBeta implements SpcBetaInterface {}

// --- service providers ---

final class SpcSimpleProvider
{
    #[Provides('spc.repository')]
    public function repository(SpcDatabase $db): SpcRepository
    {
        return new SpcRepository();
    }
}

final class SpcInjectServiceProvider
{
    #[Provides('spc.service')]
    public function service(
        #[InjectService('spc.repository')]
        SpcRepositoryInterface $repo,
    ): SpcService {
        return new SpcService();
    }
}

final class SpcValueProvider
{
    #[Provides('spc.table_driven')]
    public function tableDriven(
        #[Value('app.table_name')]
        string $tableName,
    ): SpcRepository {
        return new SpcRepository();
    }
}

final class SpcMultiProvider
{
    #[Provides('spc.alpha')]
    public function alpha(SpcDatabase $db): SpcAlpha
    {
        return new SpcAlpha();
    }

    #[Provides('spc.beta')]
    public function beta(SpcCache $cache): SpcBeta
    {
        return new SpcBeta();
    }
}

final class SpcReservedProvider
{
    #[Provides('vortos.something')]
    public function forbidden(SpcDatabase $db): SpcService
    {
        return new SpcService();
    }
}

final class SpcContainerReservedProvider
{
    #[Provides('container.something')]
    public function forbidden(SpcDatabase $db): SpcService
    {
        return new SpcService();
    }
}

final class SpcKernelReservedProvider
{
    #[Provides('kernel.something')]
    public function forbidden(SpcDatabase $db): SpcService
    {
        return new SpcService();
    }
}

final class SpcUntypedProvider
{
    #[Provides('spc.untyped')]
    public function broken($untyped): SpcService
    {
        return new SpcService();
    }
}

final class SpcNoTagProvider
{
    #[Provides('spc.ignored')]
    public function something(SpcDatabase $db): SpcService
    {
        return new SpcService();
    }
}

final class SpcAutowiredProvider
{
    public function __construct(
        private readonly SpcDatabase $db,
    ) {}

    #[Provides('spc.autowired_service')]
    public function service(): SpcService
    {
        return new SpcService();
    }
}

// --- tests ---

final class ServiceProviderCompilerPassTest extends TestCase
{
    private function container(): ContainerBuilder
    {
        return new ContainerBuilder();
    }

    private function registerProvider(ContainerBuilder $container, string $class): void
    {
        $def = new Definition($class);
        $def->addTag('vortos.service_provider');
        $container->setDefinition($class, $def);
    }

    private function processPass(ContainerBuilder $container): void
    {
        (new ServiceProviderCompilerPass())->process($container);
    }

    // -------------------------------------------------------------------------

    public function test_registers_named_service_from_provides_method(): void
    {
        $container = $this->container();
        $this->registerProvider($container, SpcSimpleProvider::class);

        $this->processPass($container);

        $this->assertTrue($container->hasDefinition('spc.repository'));
    }

    public function test_factory_points_to_provider_class_and_method(): void
    {
        $container = $this->container();
        $this->registerProvider($container, SpcSimpleProvider::class);

        $this->processPass($container);

        $def     = $container->getDefinition('spc.repository');
        $factory = $def->getFactory();

        $this->assertIsArray($factory);
        $this->assertInstanceOf(Reference::class, $factory[0]);
        $this->assertSame(SpcSimpleProvider::class, (string) $factory[0]);
        $this->assertSame('repository', $factory[1]);
    }

    public function test_typed_parameter_becomes_reference(): void
    {
        $container = $this->container();
        $this->registerProvider($container, SpcSimpleProvider::class);

        $this->processPass($container);

        $def  = $container->getDefinition('spc.repository');
        $args = $def->getArguments();

        $this->assertCount(1, $args);
        $this->assertInstanceOf(Reference::class, $args[0]);
        $this->assertSame(SpcDatabase::class, (string) $args[0]);
    }

    public function test_inject_service_parameter_becomes_named_reference(): void
    {
        $container = $this->container();
        $this->registerProvider($container, SpcInjectServiceProvider::class);

        $this->processPass($container);

        $def  = $container->getDefinition('spc.service');
        $args = $def->getArguments();

        $this->assertCount(1, $args);
        $this->assertInstanceOf(Reference::class, $args[0]);
        $this->assertSame('spc.repository', (string) $args[0]);
    }

    public function test_value_parameter_becomes_param_expression(): void
    {
        $container = $this->container();
        $this->registerProvider($container, SpcValueProvider::class);

        $this->processPass($container);

        $def  = $container->getDefinition('spc.table_driven');
        $args = $def->getArguments();

        $this->assertCount(1, $args);
        $this->assertIsString($args[0]);
        $this->assertSame('%app.table_name%', $args[0]);
    }

    public function test_does_not_override_existing_definition(): void
    {
        $container = $this->container();
        $this->registerProvider($container, SpcSimpleProvider::class);

        // Register an explicit definition for the same service ID.
        $explicit = new Definition(\stdClass::class);
        $container->setDefinition('spc.repository', $explicit);

        $this->processPass($container);

        // The explicit definition must remain untouched.
        $this->assertSame(\stdClass::class, $container->getDefinition('spc.repository')->getClass());
    }

    public function test_does_not_override_existing_alias(): void
    {
        $container = $this->container();
        $this->registerProvider($container, SpcSimpleProvider::class);
        $container->setAlias('spc.repository', SpcDatabase::class);

        $this->processPass($container);

        $this->assertTrue($container->hasAlias('spc.repository'));
        $this->assertSame(SpcDatabase::class, (string) $container->getAlias('spc.repository'));
    }

    public function test_multiple_provides_methods_on_one_provider(): void
    {
        $container = $this->container();
        $this->registerProvider($container, SpcMultiProvider::class);

        $this->processPass($container);

        $this->assertTrue($container->hasDefinition('spc.alpha'));
        $this->assertTrue($container->hasDefinition('spc.beta'));
    }

    public function test_multiple_provider_classes(): void
    {
        $container = $this->container();
        $this->registerProvider($container, SpcSimpleProvider::class);
        $this->registerProvider($container, SpcInjectServiceProvider::class);

        $this->processPass($container);

        $this->assertTrue($container->hasDefinition('spc.repository'));
        $this->assertTrue($container->hasDefinition('spc.service'));
    }

    public function test_throws_on_reserved_service_id_prefix_vortos(): void
    {
        $container = $this->container();
        $this->registerProvider($container, SpcReservedProvider::class);

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessageMatches('/reserved/i');

        $this->processPass($container);
    }

    public function test_throws_on_reserved_service_id_prefix_container(): void
    {
        $container = $this->container();
        $this->registerProvider($container, SpcContainerReservedProvider::class);

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessageMatches('/reserved/i');

        $this->processPass($container);
    }

    public function test_throws_on_reserved_service_id_prefix_kernel(): void
    {
        $container = $this->container();
        $this->registerProvider($container, SpcKernelReservedProvider::class);

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessageMatches('/reserved/i');

        $this->processPass($container);
    }

    public function test_throws_on_untyped_parameter_with_no_attribute(): void
    {
        $container = $this->container();
        $this->registerProvider($container, SpcUntypedProvider::class);

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessageMatches('/no type/i');

        $this->processPass($container);
    }

    public function test_throws_when_class_missing_service_provider_tag(): void
    {
        $container = $this->container();

        // Register without the tag.
        $def = new Definition(SpcNoTagProvider::class);
        $container->setDefinition(SpcNoTagProvider::class, $def);

        $this->processPass($container);

        // The #[Provides] method must have been ignored.
        $this->assertFalse($container->hasDefinition('spc.ignored'));
    }

    public function test_provider_class_itself_is_autowired(): void
    {
        $container = $this->container();

        // Register the provider as a normal autowired service (as the app's loader would).
        $def = (new Definition(SpcAutowiredProvider::class))
            ->setAutowired(true)
            ->addTag('vortos.service_provider');
        $container->setDefinition(SpcAutowiredProvider::class, $def);

        $this->processPass($container);

        // The provider definition itself must remain (it is a runtime service).
        $providerDef = $container->getDefinition(SpcAutowiredProvider::class);
        $this->assertTrue($providerDef->isAutowired());

        // And the provided service must reference it as the factory.
        $this->assertTrue($container->hasDefinition('spc.autowired_service'));
        $factory = $container->getDefinition('spc.autowired_service')->getFactory();
        $this->assertInstanceOf(Reference::class, $factory[0]);
        $this->assertSame(SpcAutowiredProvider::class, (string) $factory[0]);
    }
}
