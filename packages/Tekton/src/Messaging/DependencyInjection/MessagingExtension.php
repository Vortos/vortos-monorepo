<?php

declare(strict_types=1);

namespace Fortizan\Tekton\Messaging\DependencyInjection;

use Fortizan\Tekton\Messaging\Attribute\AsEventHandler;
use Fortizan\Tekton\Messaging\Attribute\AsMiddleware;
use Fortizan\Tekton\Messaging\Attribute\MessagingConfig;
use Fortizan\Tekton\Messaging\Attribute\RegisterTransport;
use Fortizan\Tekton\Messaging\Bus\EventBus;
use Fortizan\Tekton\Messaging\Command\ConsumeCommand;
use Fortizan\Tekton\Messaging\Command\ListConsumersCommand;
use Fortizan\Tekton\Messaging\Command\ListTransportsCommand;
use Fortizan\Tekton\Messaging\Command\OutboxRelayCommand;
use Fortizan\Tekton\Messaging\Command\ReplayDeadLetterCommand;
use Fortizan\Tekton\Messaging\Contract\ConsumerInterface;
use Fortizan\Tekton\Messaging\Contract\ConsumerLocatorInterface;
use Fortizan\Tekton\Messaging\Contract\EventBusInterface;
use Fortizan\Tekton\Messaging\Contract\OutboxInterface;
use Fortizan\Tekton\Messaging\Contract\OutboxPollerInterface;
use Fortizan\Tekton\Messaging\Contract\ProducerInterface;
use Fortizan\Tekton\Messaging\DeadLetter\DeadLetterWriter;
use Fortizan\Tekton\Messaging\DependencyInjection\Compiler\HandlerDiscoveryCompilerPass;
use Fortizan\Tekton\Messaging\DependencyInjection\Compiler\HookDiscoveryCompilerPass;
use Fortizan\Tekton\Messaging\DependencyInjection\Compiler\MessagingConfigCompilerPass;
use Fortizan\Tekton\Messaging\DependencyInjection\Compiler\MiddlewareCompilerPass;
use Fortizan\Tekton\Messaging\DependencyInjection\Compiler\TransportRegistryCompilerPass;
use Fortizan\Tekton\Messaging\Driver\InMemory\Runtime\InMemoryBroker;
use Fortizan\Tekton\Messaging\Driver\InMemory\Runtime\InMemoryConsumer;
use Fortizan\Tekton\Messaging\Driver\InMemory\Runtime\InMemoryProducer;
use Fortizan\Tekton\Messaging\Driver\Kafka\Factory\KafkaConsumerFactory;
use Fortizan\Tekton\Messaging\Driver\Kafka\Factory\KafkaProducerFactory;
use Fortizan\Tekton\Messaging\Driver\Kafka\Runtime\KafkaProducer;
use Fortizan\Tekton\Messaging\Hook\Attribute\AfterConsume;
use Fortizan\Tekton\Messaging\Hook\Attribute\AfterDispatch;
use Fortizan\Tekton\Messaging\Hook\Attribute\BeforeConsume;
use Fortizan\Tekton\Messaging\Hook\Attribute\BeforeDispatch;
use Fortizan\Tekton\Messaging\Hook\Attribute\PreSend;
use Fortizan\Tekton\Messaging\Hook\HookDescriptor;
use Fortizan\Tekton\Messaging\Hook\HookRegistry;
use Fortizan\Tekton\Messaging\Hook\HookRunner;
use Fortizan\Tekton\Messaging\Middleware\Consumer\TransactionalMiddleware;
use Fortizan\Tekton\Messaging\Middleware\Core\HookMiddleware;
use Fortizan\Tekton\Messaging\Middleware\Core\LoggingMiddleware;
use Fortizan\Tekton\Messaging\Middleware\Core\TracingMiddleware;
use Fortizan\Tekton\Messaging\Middleware\MiddlewareStack;
use Fortizan\Tekton\Messaging\Outbox\OutboxPoller;
use Fortizan\Tekton\Messaging\Outbox\OutboxRelayWorker;
use Fortizan\Tekton\Messaging\Outbox\OutboxWriter;
use Fortizan\Tekton\Messaging\Registry\ConsumerRegistry;
use Fortizan\Tekton\Messaging\Registry\HandlerRegistry;
use Fortizan\Tekton\Messaging\Registry\ProducerRegistry;
use Fortizan\Tekton\Messaging\Registry\TransportRegistry;
use Fortizan\Tekton\Messaging\Runtime\ConsumerLocator;
use Fortizan\Tekton\Messaging\Runtime\ConsumerRunner;
use Fortizan\Tekton\Messaging\Runtime\OutboxRelayRunner;
use Fortizan\Tekton\Messaging\Serializer\JsonSerializer;
use Fortizan\Tekton\Messaging\Serializer\SerializerLocator;
use Psr\SimpleCache\CacheInterface;
use ReflectionMethod;
use Reflector;
use Symfony\Component\DependencyInjection\ChildDefinition;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\Extension;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\DependencyInjection\ServiceLocator;

final class MessagingExtension extends Extension
{
    public function getAlias(): string
    {
        return 'tekton_messaging';
    }

    public function load(array $configs, ContainerBuilder $container)
    {
        if (!$container->hasParameter('tekton.event_producer_map')) {
            $container->setParameter('tekton.event_producer_map', []);
        }
        if (!$container->hasParameter('tekton.handlers')) {
            $container->setParameter('tekton.handlers', []);
        }
        if (!$container->hasParameter('tekton.hooks')) {
            $container->setParameter('tekton.hooks', []);
        }

        $tektonConfig = new TektonMessagingConfig();
        foreach ($configs as $config) {
            if ($config instanceof \Closure) {
                $config($tektonConfig);
            }
        }
        $resolvedConfig = $this->processConfiguration(
            new Configuration(),
            [$tektonConfig->toArray()]
        );

        $this->registerMessagingAttributes($container);
        $this->registerMessagingConfigAttributes($container);
        $this->registerRegistries($container);
        $this->registerSerializers($container);
        $this->registerMiddlewares($container);
        $this->registerMiddlewareStack($container);
        $this->registerOutbox($container);
        $this->registerDeadLetterWriter($container);
        $this->registerInMemoryDriver($container);
        $this->registerKafkaDrivers($container);
        $this->registerEventBus($container);
        $this->registerConsumerRunner($container);
        $this->registerCLICommands($container);
        $this->registerDefaultDriverInterfaces($container, $resolvedConfig['driver']);
        $this->registerHooks($container);
        $this->registerIdempotency($container);

        $container->addCompilerPass(new MessagingConfigCompilerPass());
        $container->addCompilerPass(new HandlerDiscoveryCompilerPass());
        $container->addCompilerPass(new TransportRegistryCompilerPass());
        $container->addCompilerPass(new MiddlewareCompilerPass());
        $container->addCompilerPass(new HookDiscoveryCompilerPass());
    }

    private function registerIdempotency(ContainerBuilder $container): void
    {
        if (!$container->hasAlias(CacheInterface::class) && !$container->hasDefinition(CacheInterface::class)) {
            $container->setAlias(CacheInterface::class, 'cache.app.simple')
                ->setPublic(false);
        }
    }

    private function registerHooks(ContainerBuilder $container): void
    {
        $container->register('tekton.hook_locator', ServiceLocator::class)
            ->setArguments([[]])
            ->setPublic(false);

        $container->register(HookRegistry::class, HookRegistry::class)
            ->setArgument('$hooks', '%tekton.hooks%')
            ->setPublic(false);

        $container->register(HookRunner::class, HookRunner::class)
            ->setAutowired(true)
            ->setArgument('$hookLocator', new Reference('tekton.hook_locator'))
            ->setPublic(false);

        $container->register(HookMiddleware::class, HookMiddleware::class)
            ->setAutowired(true)
            ->setPublic(false);

        foreach ($this->hookAttributeMap() as $attributeClass => $hookType) {
            $container->registerAttributeForAutoconfiguration(
                $attributeClass,
                static function (ChildDefinition $definition) use ($hookType): void {
                    $definition->addTag('tekton.hook', ['type' => $hookType]);
                    $definition->setPublic(true);
                }
            );
        }
    }

    /**
     * @return array<class-string, string>
     */
    private function hookAttributeMap(): array
    {
        return [
            BeforeDispatch::class => HookDescriptor::BEFORE_DISPATCH,
            AfterDispatch::class  => HookDescriptor::AFTER_DISPATCH,
            PreSend::class        => HookDescriptor::PRE_SEND,
            BeforeConsume::class  => HookDescriptor::BEFORE_CONSUME,
            AfterConsume::class   => HookDescriptor::AFTER_CONSUME,
        ];
    }

    private function registerDefaultDriverInterfaces(ContainerBuilder $container, array $driver): void
    {
        $container->setAlias(ProducerInterface::class, $driver['producer'])
            ->setPublic(false);

        if (!empty($driver['consumer'])) {
            $container->setAlias(ConsumerInterface::class, $driver['consumer'])
                ->setPublic(false);
        }
    }

    private function registerCLICommands(ContainerBuilder $container): void
    {
        $container->register(ConsumeCommand::class, ConsumeCommand::class)
            ->setAutowired(true)
            ->setAutoconfigured(true)
            ->addTag('console.command')
            ->setPublic(false);

        $container->register(OutboxRelayCommand::class, OutboxRelayCommand::class)
            ->setAutowired(true)
            ->setAutoconfigured(true)
            ->addTag('console.command')
            ->setPublic(false);

        $container->register(ListConsumersCommand::class, ListConsumersCommand::class)
            ->setAutowired(true)
            ->setAutoconfigured(true)
            ->addTag('console.command')
            ->setPublic(false);

        $container->register(ListTransportsCommand::class, ListTransportsCommand::class)
            ->setAutowired(true)
            ->setPublic(false)
            ->addTag('console.command');

        $container->register(ReplayDeadLetterCommand::class, ReplayDeadLetterCommand::class)
            ->setAutowired(true)
            ->setPublic(false)
            ->addTag('console.command');
    }

    private function registerConsumerRunner(ContainerBuilder $container): void
    {
        $container->register(ConsumerLocator::class, ConsumerLocator::class)
            ->setAutowired(true)
            ->setPublic(false);

        $container->setAlias(ConsumerLocatorInterface::class, ConsumerLocator::class)
            ->setPublic(false);
            
        $container->register('tekton.handler_locator', ServiceLocator::class)
            ->setArguments([[]])  // HandlerDiscoveryCompilerPass fills this
            ->setPublic(false);

        $container->register(ConsumerRunner::class, ConsumerRunner::class)
            ->setAutowired(true)
            ->setAutoconfigured(true)
            ->setArgument('$handlerLocator', new Reference('tekton.handler_locator'))
            ->setPublic(false);
    }

    private function registerEventBus(ContainerBuilder $container): void
    {
        $container->register(EventBus::class, EventBus::class)
            ->setAutowired(true)
            ->setAutoconfigured(true)
            ->setArgument('$eventProducerMap', '%tekton.event_producer_map%')
            ->setPublic(false);

        $container->setAlias(EventBusInterface::class, EventBus::class)
            ->setPublic(true);
    }

    private function registerKafkaDrivers(ContainerBuilder $container): void
    {
        $container->register(KafkaProducer::class, KafkaProducer::class)
            ->setAutowired(true)
            ->setAutoconfigured(true)
            ->setShared(false)
            ->setPublic(false);

        // $container->register(KafkaConsumer::class, KafkaConsumer::class)
        //     ->setAutowired(true)
        //     ->setAutoconfigured(true)
        //     ->setShared(false)
        //     ->setPublic(false);

        $container->register(KafkaProducerFactory::class, KafkaProducerFactory::class)
            ->setAutowired(true)
            ->setPublic(false);

        $container->register(KafkaConsumerFactory::class, KafkaConsumerFactory::class)
            ->setAutowired(true)
            ->setPublic(false);
    }

    private function registerInMemoryDriver(ContainerBuilder $container): void
    {
        $container->register(InMemoryBroker::class, InMemoryBroker::class)
            ->setShared(true)
            ->setPublic(false);

        $container->register(InMemoryProducer::class, InMemoryProducer::class)
            ->setAutowired(true)
            ->setAutoconfigured(true)
            ->setPublic(false);

        $container->register(InMemoryConsumer::class, InMemoryConsumer::class)
            ->setAutowired(true)
            ->setAutoconfigured(true)
            ->setPublic(false);
    }

    private function registerDeadLetterWriter(ContainerBuilder $container): void
    {
        $container->register(DeadLetterWriter::class, DeadLetterWriter::class)
            ->setAutowired(true)
            ->setAutoconfigured(true)
            ->setPublic(false);
    }

    private function registerOutbox(ContainerBuilder $container): void
    {
        $container->register(OutboxWriter::class, OutboxWriter::class)
            ->setAutowired(true)
            ->setAutoconfigured(true)
            ->setPublic(false);

        $container->setAlias(OutboxInterface::class, OutboxWriter::class)
            ->setPublic(false);

        $container->register(OutboxPoller::class, OutboxPoller::class)
            ->setAutowired(true)
            ->setAutoconfigured(true)
            ->setPublic(false);

        $container->setAlias(OutboxPollerInterface::class, OutboxPoller::class)
            ->setPublic(false);

        $container->register(OutboxRelayWorker::class, OutboxRelayWorker::class)
            ->setAutowired(true)
            ->setAutoconfigured(true)
            ->setPublic(false);

        $container->register(OutboxRelayRunner::class, OutboxRelayRunner::class)
            ->setAutowired(true)
            ->setAutoconfigured(true)
            ->setPublic(false);
    }

    private function registerMiddlewares(ContainerBuilder $container): void
    {
        $container->register(TracingMiddleware::class, TracingMiddleware::class)
            ->setAutowired(true)
            ->setAutoconfigured(true);

        $container->register(LoggingMiddleware::class, LoggingMiddleware::class)
            ->setAutowired(true)
            ->setAutoconfigured(true);

        $container->register(TransactionalMiddleware::class, TransactionalMiddleware::class)
            ->setAutowired(true)
            ->setAutoconfigured(true);
    }

    private function registerMiddlewareStack(ContainerBuilder $container): void
    {
        // Core middlewares are registered here in fixed order.
        // User-defined middlewares tagged 'tekton.middleware' are appended
        // by MiddlewareCompilerPass after these, sorted by priority.

        $container->register(MiddlewareStack::class, MiddlewareStack::class)
            ->setArgument('$middlewares', [
                new Reference(TracingMiddleware::class),
                new Reference(LoggingMiddleware::class),
                new Reference(HookMiddleware::class),
                new Reference(TransactionalMiddleware::class),
            ])
            ->setPublic(false);
    }

    private function registerSerializers(ContainerBuilder $container): void
    {
        $container->register(JsonSerializer::class, JsonSerializer::class)
            ->setAutowired(true)
            ->setAutoconfigured(true);

        $container->register(SerializerLocator::class, SerializerLocator::class)
            ->setArgument('$serializers', ['json' => new Reference(JsonSerializer::class)])
            ->setPublic(false);
    }

    private function registerRegistries(ContainerBuilder $container): void
    {
        $container->register(TransportRegistry::class, TransportRegistry::class)
            ->setAutowired(true)
            ->setAutoconfigured(true)
            ->setPublic(false);

        $container->register(ProducerRegistry::class, ProducerRegistry::class)
            ->setAutowired(true)
            ->setAutoconfigured(true)
            ->setPublic(false);

        $container->register(ConsumerRegistry::class, ConsumerRegistry::class)
            ->setAutowired(true)
            ->setAutoconfigured(true)
            ->setPublic(false);

        $container->register(HandlerRegistry::class, HandlerRegistry::class)
            ->setAutowired(true)
            ->setAutoconfigured(true)
            ->setPublic(false);
    }

    private function registerMessagingConfigAttributes(ContainerBuilder $container): void
    {
        $container->registerAttributeForAutoconfiguration(
            MessagingConfig::class,
            static function (ChildDefinition $definition, MessagingConfig $attribute): void {
                $definition->addTag('tekton.messaging_config');
            }
        );
    }

    private function registerMessagingAttributes(ContainerBuilder $container): void
    {
        $container->registerAttributeForAutoconfiguration(
            AsEventHandler::class,
            static function (ChildDefinition $definition, AsEventHandler $attribute, Reflector $reflector) {

                $tagAttributes = [
                    'consumer' => $attribute->consumer,
                    'priority' => $attribute->priority,
                    'idempotent' => $attribute->idempotent,
                    'version' => $attribute->version,
                    'method' => null
                ];

                if ($reflector instanceof ReflectionMethod) {
                    $tagAttributes['method'] = $reflector->getName();
                }

                $definition->addTag('tekton.event_handler', $tagAttributes);
                $definition->setPublic(true);
            }
        );

        $container->registerAttributeForAutoconfiguration(
            RegisterTransport::class,
            static function (ChildDefinition $definition, RegisterTransport $attribute) {
                $definition->addTag('tekton.messenger.transport.definition');
                $definition->setPublic(true);
            }
        );

        $container->registerAttributeForAutoconfiguration(
            AsMiddleware::class,
            static function (ChildDefinition $definition, AsMiddleware $attribute) {

                $tagAttributes = [
                    'priority' => $attribute->priority
                ];

                $definition->addTag('tekton.middleware', $tagAttributes);
                $definition->setPublic(true);
            }
        );
    }
}
