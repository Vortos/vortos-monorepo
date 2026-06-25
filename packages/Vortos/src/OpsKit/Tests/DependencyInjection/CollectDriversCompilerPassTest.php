<?php

declare(strict_types=1);

namespace Vortos\OpsKit\Tests\DependencyInjection;

use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\Compiler\PassConfig;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\DependencyInjection\ServiceLocator;
use Vortos\OpsKit\Driver\Exception\DriverCollectionException;
use Vortos\OpsKit\Tests\Fixtures\Demo\BadKeyGreeter;
use Vortos\OpsKit\Tests\Fixtures\Demo\BrokenGreeter;
use Vortos\OpsKit\Tests\Fixtures\Demo\CollectGreeterDriversPass;
use Vortos\OpsKit\Tests\Fixtures\Demo\GreeterRegistry;
use Vortos\OpsKit\Tests\Fixtures\Demo\LoudGreeter;
use Vortos\OpsKit\Tests\Fixtures\Demo\QuietGreeter;

final class CollectDriversCompilerPassTest extends TestCase
{
    public function test_two_drivers_register_and_resolve_by_key(): void
    {
        $container = $this->container();
        $this->registerDriver($container, 'loud_svc', LoudGreeter::class);
        $this->registerDriver($container, 'quiet_svc', QuietGreeter::class);
        $container->compile();

        $registry = $container->get(GreeterRegistry::class);
        $this->assertInstanceOf(GreeterRegistry::class, $registry);
        $this->assertInstanceOf(LoudGreeter::class, $registry->get('loud'));
        $this->assertInstanceOf(QuietGreeter::class, $registry->get('quiet'));
        $this->assertSame(['loud', 'quiet'], $registry->keys());
    }

    public function test_uninstalled_driver_is_simply_absent_no_runtime_error(): void
    {
        $container = $this->container();
        $this->registerDriver($container, 'loud_svc', LoudGreeter::class);
        $container->compile();

        $registry = $container->get(GreeterRegistry::class);
        $this->assertTrue($registry->has('loud'));
        $this->assertFalse($registry->has('quiet'));
    }

    public function test_tag_key_overrides_attribute_key(): void
    {
        $container = $this->container();
        $container->register('custom_svc', LoudGreeter::class)
            ->addTag(CollectGreeterDriversPass::TAG, ['key' => 'custom'])
            ->setPublic(true);
        $container->compile();

        $registry = $container->get(GreeterRegistry::class);
        $this->assertTrue($registry->has('custom'));
        $this->assertFalse($registry->has('loud'));
    }

    public function test_duplicate_key_fails_at_compile_time(): void
    {
        $container = $this->container();
        $this->registerDriver($container, 'first', LoudGreeter::class);
        $this->registerDriver($container, 'second', LoudGreeter::class);

        $this->expectException(DriverCollectionException::class);
        $this->expectExceptionMessageMatches("/key 'loud'/");
        $container->compile();
    }

    public function test_missing_attribute_key_fails_at_compile_time(): void
    {
        $container = $this->container();
        $this->registerDriver($container, 'broken', BrokenGreeter::class);

        $this->expectException(DriverCollectionException::class);
        $this->expectExceptionMessageMatches('/#\[AsDriver/');
        $container->compile();
    }

    public function test_malformed_key_fails_at_compile_time(): void
    {
        $container = $this->container();
        $this->registerDriver($container, 'badkey', BadKeyGreeter::class);

        $this->expectException(DriverCollectionException::class);
        $this->expectExceptionMessageMatches('/lower-kebab/');
        $container->compile();
    }

    public function test_pass_is_a_noop_when_locator_absent(): void
    {
        // A concern whose registry/locator is not registered must not blow up:
        // there is simply nothing to collect into.
        $container = new ContainerBuilder();
        $this->registerDriver($container, 'loud_svc', LoudGreeter::class);
        $container->addCompilerPass(new CollectGreeterDriversPass(), PassConfig::TYPE_BEFORE_OPTIMIZATION, -32);

        $container->compile();
        $this->assertFalse($container->has(CollectGreeterDriversPass::LOCATOR_ID));
    }

    private function container(): ContainerBuilder
    {
        $container = new ContainerBuilder();

        $container->register(CollectGreeterDriversPass::LOCATOR_ID, ServiceLocator::class)
            ->setArguments([[]])
            ->addTag('container.service_locator')
            ->setPublic(true);

        $container->register(GreeterRegistry::class, GreeterRegistry::class)
            ->setArguments([new Reference(CollectGreeterDriversPass::LOCATOR_ID)])
            ->setPublic(true);

        $container->addCompilerPass(new CollectGreeterDriversPass(), PassConfig::TYPE_BEFORE_OPTIMIZATION, -32);

        return $container;
    }

    private function registerDriver(ContainerBuilder $container, string $id, string $class): void
    {
        $container->register($id, $class)
            ->addTag(CollectGreeterDriversPass::TAG)
            ->setPublic(true);
    }
}
