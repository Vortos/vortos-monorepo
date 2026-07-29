<?php

declare(strict_types=1);

namespace Vortos\Metrics\Tests\AutoInstrumentation;

use Doctrine\DBAL\Configuration;
use Doctrine\ORM\EntityManager;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;
use Vortos\Metrics\AutoInstrumentation\PersistenceMetricsCompilerPass;
use Vortos\Metrics\AutoInstrumentation\PersistenceMetricsDecorator;
use Vortos\Metrics\Telemetry\FrameworkTelemetry;

final class PersistenceMetricsCompilerPassTest extends TestCase
{
    private function container(): ContainerBuilder
    {
        $c = new ContainerBuilder();
        $c->register(FrameworkTelemetry::class, FrameworkTelemetry::class);

        return $c;
    }

    /** @return list<string> */
    private function ormMiddlewareClasses(ContainerBuilder $c): array
    {
        $args = $c->getDefinition(EntityManager::class)->getArguments();
        $mw = $args[4] ?? [];

        return array_map(static fn (Reference $r): string => (string) $r, is_array($mw) ? $mw : []);
    }

    /**
     * The regression. With vortos-persistence-orm installed, the connection the app actually uses
     * is built by the ORM and never sees the DBAL Configuration, so wiring only that path meant
     * db_queries_total was never emitted on any ORM install.
     */
    public function test_injects_into_the_orm_connection(): void
    {
        $c = $this->container();
        $c->register(EntityManager::class, EntityManager::class)
            ->setArguments(['dsn', [], false, null]);

        (new PersistenceMetricsCompilerPass())->process($c);

        self::assertContains(PersistenceMetricsDecorator::class, $this->ormMiddlewareClasses($c));
    }

    public function test_appends_rather_than_replacing_middleware_another_pass_added(): void
    {
        $c = $this->container();
        $c->register(EntityManager::class, EntityManager::class)
            ->setArguments(['dsn', [], false, null, [new Reference('some.other.middleware')]]);

        (new PersistenceMetricsCompilerPass())->process($c);

        $classes = $this->ormMiddlewareClasses($c);

        // Assigning this slot instead of appending is how one pass silently discarded another's
        // middleware, depending on which ran last.
        self::assertContains('some.other.middleware', $classes);
        self::assertContains(PersistenceMetricsDecorator::class, $classes);
    }

    public function test_pads_the_argument_list_when_optional_arguments_were_omitted(): void
    {
        $c = $this->container();
        $c->register(EntityManager::class, EntityManager::class)->setArguments(['dsn', []]);

        (new PersistenceMetricsCompilerPass())->process($c);

        self::assertContains(PersistenceMetricsDecorator::class, $this->ormMiddlewareClasses($c));
    }

    public function test_still_injects_into_the_dbal_stack(): void
    {
        $c = $this->container();
        $c->register(Configuration::class, Configuration::class)
            ->addMethodCall('setMiddlewares', [[new Reference('tracing.middleware')]]);

        (new PersistenceMetricsCompilerPass())->process($c);

        $calls = $c->getDefinition(Configuration::class)->getMethodCalls();
        $mw = array_map(static fn (Reference $r): string => (string) $r, $calls[0][1][0]);

        self::assertSame(['tracing.middleware', PersistenceMetricsDecorator::class], $mw);
    }

    public function test_wires_both_stacks_when_both_packages_are_installed(): void
    {
        $c = $this->container();
        $c->register(Configuration::class, Configuration::class)
            ->addMethodCall('setMiddlewares', [[]]);
        $c->register(EntityManager::class, EntityManager::class)
            ->setArguments(['dsn', [], false, null]);

        (new PersistenceMetricsCompilerPass())->process($c);

        self::assertContains(PersistenceMetricsDecorator::class, $this->ormMiddlewareClasses($c));
        $calls = $c->getDefinition(Configuration::class)->getMethodCalls();
        self::assertCount(1, $calls[0][1][0]);
    }

    public function test_does_nothing_when_the_persistence_module_is_disabled(): void
    {
        $c = $this->container();
        $c->setParameter('vortos.metrics.disabled_modules', ['persistence']);
        $c->register(EntityManager::class, EntityManager::class)->setArguments(['dsn', [], false, null]);

        (new PersistenceMetricsCompilerPass())->process($c);

        self::assertFalse($c->hasDefinition(PersistenceMetricsDecorator::class));
    }

    public function test_does_nothing_without_a_persistence_layer(): void
    {
        $c = $this->container();

        (new PersistenceMetricsCompilerPass())->process($c);

        self::assertFalse($c->hasDefinition(PersistenceMetricsDecorator::class));
    }
}
