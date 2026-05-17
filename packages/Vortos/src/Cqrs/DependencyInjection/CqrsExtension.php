<?php

declare(strict_types=1);

namespace Vortos\Cqrs\DependencyInjection;

use Psr\Log\LoggerInterface;
use ReflectionMethod;
use Reflector;
use Symfony\Component\DependencyInjection\ChildDefinition;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\DependencyInjection\Extension\Extension;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\DependencyInjection\ServiceLocator;
use Vortos\Cqrs\Attribute\AsCommandHandler;
use Vortos\Cqrs\Attribute\AsProjectionHandler;
use Vortos\Cqrs\Attribute\AsQueryHandler;
use Vortos\Cqrs\Command\CommandBus;
use Vortos\Cqrs\Command\CommandBusInterface;
use Vortos\Cqrs\Command\Idempotency\CommandIdempotencyStoreInterface;
use Vortos\Cqrs\Command\Idempotency\InMemoryCommandIdempotencyStore;
use Vortos\Cqrs\Command\Idempotency\RedisCommandIdempotencyStore;
use Vortos\Cqrs\Query\QueryBus;
use Vortos\Cqrs\Query\QueryBusInterface;
use Vortos\Cqrs\Validation\VortosValidator;
use Vortos\Metrics\Telemetry\FrameworkTelemetry;
use Vortos\Cache\Contract\AtomicCacheInterface;
use Vortos\Messaging\Contract\EventBusInterface;
use Vortos\Persistence\Transaction\UnitOfWorkInterface;
use Vortos\Tracing\Contract\TracingInterface;
use Vortos\Config\DependencyInjection\ConfigExtension;
use Vortos\Config\Stub\ConfigStub;

final class CqrsExtension extends Extension
{
    public function getAlias(): string
    {
        return 'vortos_cqrs';
    }

    public function load(array $configs, ContainerBuilder $container): void
    {
        $projectDir = $container->getParameter('kernel.project_dir');
        $env        = $container->getParameter('kernel.env');

        $config = new VortosCqrsConfig();

        $base = $projectDir . '/config/cqrs.php';
        if (file_exists($base)) {
            (require $base)($config);
        }

        $envFile = $projectDir . '/config/' . $env . '/cqrs.php';
        if (file_exists($envFile)) {
            (require $envFile)($config);
        }

        $resolved = $this->processConfiguration(new Configuration(), [$config->toArray()]);

        $container->setParameter('vortos.cqrs.idempotency_strategies', []);
        $container->setParameter('vortos.cqrs.command_handler_map', []);

        $this->registerValidator($container);
        $this->registerCommandBus($container, $resolved['command_bus']);
        $this->registerQueryBus($container);
        $this->registerAutoconfiguration($container);
    }

    private function registerValidator(ContainerBuilder $container): void
    {
        $container->register(VortosValidator::class, VortosValidator::class)
            ->setShared(true)
            ->setPublic(false);
    }

    private function registerCommandBus(ContainerBuilder $container, array $config): void
    {
        $container->register(RedisCommandIdempotencyStore::class, RedisCommandIdempotencyStore::class)
            ->setArgument('$cache', new Reference(AtomicCacheInterface::class))
            ->setPublic(false);

        $container->register(InMemoryCommandIdempotencyStore::class, InMemoryCommandIdempotencyStore::class)
            ->setPublic(false);

        $container->setAlias(CommandIdempotencyStoreInterface::class, $config['idempotency_store'])
            ->setPublic(false);

        $container->register('vortos.command_handler_locator', ServiceLocator::class)
            ->setArguments([[]])
            ->addTag('container.service_locator')
            ->setPublic(false);

        $container->register(CommandBus::class, CommandBus::class)
            ->setArguments([
                new Reference('vortos.command_handler_locator'),
                new Reference(UnitOfWorkInterface::class),
                new Reference(EventBusInterface::class),
                new Reference(CommandIdempotencyStoreInterface::class),
                new Reference(LoggerInterface::class),
                new Reference(TracingInterface::class),
                '%vortos.cqrs.idempotency_strategies%',
                new Reference(VortosValidator::class),
            ])
            ->setPublic(false);

        $container->setAlias(CommandBusInterface::class, CommandBus::class)
            ->setPublic(true);
    }

    private function registerQueryBus(ContainerBuilder $container): void
    {
        $container->register('vortos.query_handler_locator', ServiceLocator::class)
            ->setArguments([[]])
            ->addTag('container.service_locator')
            ->setPublic(false);

        $container->register(QueryBus::class, QueryBus::class)
            ->setArgument('$handlerLocator', new Reference('vortos.query_handler_locator'))
            ->setArgument('$logger', new Reference(LoggerInterface::class, ContainerInterface::NULL_ON_INVALID_REFERENCE))
            ->setArgument('$telemetry', new Reference(FrameworkTelemetry::class, ContainerInterface::NULL_ON_INVALID_REFERENCE))
            ->setArgument('$tracer', new Reference(TracingInterface::class, ContainerInterface::NULL_ON_INVALID_REFERENCE))
            ->setPublic(false);

        $container->setAlias(QueryBusInterface::class, QueryBus::class)
            ->setPublic(true);
    }

    private function registerAutoconfiguration(ContainerBuilder $container): void
    {
        $container->registerAttributeForAutoconfiguration(
            AsCommandHandler::class,
            static function (ChildDefinition $definition, AsCommandHandler $attribute): void {
                $definition->addTag('vortos.command_handler', []);
                $definition->setPublic(true);
            },
        );

        $container->registerAttributeForAutoconfiguration(
            AsQueryHandler::class,
            static function (ChildDefinition $definition, AsQueryHandler $attribute): void {
                $definition->addTag('vortos.query_handler', []);
                $definition->setPublic(true);
            },
        );

        $container->registerAttributeForAutoconfiguration(
            AsProjectionHandler::class,
            static function (ChildDefinition $definition, AsProjectionHandler $attribute, Reflector $reflector): void {
                $definition->addTag('vortos.projection_handler', [
                    'consumer'   => $attribute->consumer,
                    'handlerId'  => $attribute->handlerId,
                    'priority'   => $attribute->priority,
                    'idempotent' => true,
                    'method'     => $reflector instanceof ReflectionMethod ? $reflector->getName() : null,
                ]);
                $definition->setPublic(true);
            },
        );

        $container->register('vortos.config_stub.cqrs', ConfigStub::class)
            ->setArguments(['cqrs', __DIR__ . '/../stubs/cqrs.php'])
            ->addTag(ConfigExtension::STUB_TAG)
            ->setPublic(false);
    }
}
