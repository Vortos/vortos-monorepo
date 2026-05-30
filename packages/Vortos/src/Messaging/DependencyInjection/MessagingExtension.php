<?php

declare(strict_types=1);

namespace Vortos\Messaging\DependencyInjection;

use Vortos\Messaging\Attribute\AsEventHandler;
use Vortos\Messaging\Attribute\AsMiddleware;
use Vortos\Messaging\Attribute\MessagingConfig;
use Vortos\Messaging\Attribute\RegisterTransport;
use Vortos\Messaging\Bus\EventBus;
use Vortos\Messaging\Bus\StandaloneEventBus;
use Vortos\Messaging\Contract\ConsumerInterface;
use Vortos\Messaging\Contract\ConsumerLocatorInterface;
use Vortos\Messaging\Contract\EventBusInterface;
use Vortos\Messaging\Contract\OutboxInterface;
use Vortos\Messaging\Contract\OutboxPollerInterface;
use Vortos\Messaging\Contract\PayloadSanitizerInterface;
use Vortos\Messaging\Contract\ProducerInterface;
use Vortos\Messaging\Contract\StandaloneEventBusInterface;
use Vortos\Messaging\DeadLetter\DeadLetterWriter;
use Vortos\Messaging\DeadLetter\NullPayloadSanitizer;
use Vortos\Messaging\Driver\InMemory\Runtime\InMemoryBroker;
use Vortos\Messaging\Driver\InMemory\Runtime\InMemoryConsumer;
use Vortos\Messaging\Driver\InMemory\Runtime\InMemoryProducer;
use Vortos\Messaging\Driver\Kafka\Factory\KafkaConsumerFactory;
use Vortos\Messaging\Driver\Kafka\Factory\KafkaProducerFactory;
use Vortos\Messaging\Driver\Kafka\Health\KafkaHealthCheck;
use Vortos\Messaging\Driver\Kafka\Runtime\KafkaProducer;
use Vortos\Messaging\Driver\Kafka\Runtime\LazyKafkaProducer;
use Vortos\Messaging\Hook\Attribute\AfterConsume;
use Vortos\Messaging\Hook\Attribute\AfterDispatch;
use Vortos\Messaging\Hook\Attribute\AfterHandler;
use Vortos\Messaging\Hook\Attribute\BeforeConsume;
use Vortos\Messaging\Hook\Attribute\BeforeDispatch;
use Vortos\Messaging\Hook\Attribute\BeforeHandler;
use Vortos\Messaging\Hook\Attribute\PreSend;
use Vortos\Messaging\Hook\HookDescriptor;
use Vortos\Messaging\Hook\HookRegistry;
use Vortos\Messaging\Hook\HookRunner;
use Vortos\Messaging\Middleware\Consumer\TransactionalMiddleware;
use Vortos\Messaging\Middleware\Core\HookMiddleware;
use Vortos\Messaging\Middleware\Core\LoggingMiddleware;
use Vortos\Messaging\Middleware\Core\TracingMiddleware;
use Vortos\Messaging\Middleware\MiddlewareStack;
use Vortos\Messaging\Outbox\OutboxPoller;
use Vortos\Messaging\Outbox\OutboxRelayWorker;
use Vortos\Messaging\Outbox\OutboxWriter;
use Vortos\Messaging\Registry\ConsumerRegistry;
use Vortos\Messaging\Registry\HandlerRegistry;
use Vortos\Messaging\Registry\ProducerRegistry;
use Vortos\Messaging\Registry\TransportRegistry;
use Vortos\Messaging\Runtime\ConsumerLocator;
use Vortos\Messaging\Dev\SyncProjectionEventBusDecorator;
use Vortos\Messaging\DeadLetter\DeadLetterRepositoryInterface;
use Vortos\Messaging\Runtime\ConsumerRunner;
use Vortos\Messaging\Runtime\ConsumerRunnerInterface;
use Vortos\Messaging\Runtime\OutboxRelayRunner;
use Vortos\Messaging\Serializer\JsonSerializer;
use Vortos\Messaging\Serializer\SerializerLocator;
use ReflectionMethod;
use Reflector;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\DependencyInjection\ChildDefinition;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\DependencyInjection\Extension\Extension;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\DependencyInjection\ServiceLocator;
use Symfony\Component\Messenger\MessageBus;
use Symfony\Component\Messenger\MessageBusInterface;
use Vortos\Persistence\Transaction\UnitOfWorkInterface;
use Vortos\Config\DependencyInjection\ConfigExtension;
use Vortos\Config\Stub\ConfigStub;

final class MessagingExtension extends Extension
{
    public function getAlias(): string
    {
        return 'vortos_messaging';
    }

    public function load(array $configs, ContainerBuilder $container):void
    {
        $this->initializeParameters($container);

        $projectDir = $container->getParameter('kernel.project_dir');
        $env = $container->getParameter('kernel.env');

        $vortosConfig = new VortosMessagingConfig();

        $base = $projectDir . '/config/messaging.php';
        if (file_exists($base)) {
            (require $base)($vortosConfig);
        }

        $envFile = $projectDir . '/config/' . $env . '/messaging.php';
        if (file_exists($envFile)) {
            (require $envFile)($vortosConfig);
        }

        $resolvedConfig = $this->processConfiguration(new Configuration(), [$vortosConfig->toArray()]);

        // $container->register(MessageBus::class, MessageBus::class)
        //     ->setArguments([[]])
        //     ->setShared(true)
        //     ->setPublic(false);

        // $container->setAlias(MessageBusInterface::class, MessageBus::class)
        //     ->setPublic(false);
            
        $this->registerInProcessBus($container);
        $this->registerMessagingAttributes($container);
        $this->registerMessagingConfigAttributes($container);
        $this->registerRegistries($container);
        $this->registerSerializers($container);
        $this->registerMiddlewares($container);
        $this->registerMiddlewareStack($container);
        $prefix = $container->hasParameter('vortos.db.framework_table_prefix')
            ? $container->getParameter('vortos.db.framework_table_prefix')
            : 'vortos_';
        $outboxConfig = $resolvedConfig['outbox'];
        $outboxConfig['table'] = $prefix . $outboxConfig['table'];
        $this->registerOutbox($container, $outboxConfig);
        $this->registerDeadLetterWriter($container, $prefix . $resolvedConfig['dlq']['table']);
        $this->registerInMemoryDriver($container);
        $this->registerKafkaDrivers($container);
        $this->registerEventBus($container);
        $this->registerSyncProjectionDecorator($container, $env);
        $this->registerStandaloneEventBus($container);
        $this->registerConsumerRunner($container, $resolvedConfig['consumer_defaults']['idempotency_ttl']);
        $this->registerCLICommands($container);
        $this->registerDefaultDriverInterfaces($container, $resolvedConfig['driver']);
        $this->registerHooks($container);
        $this->registerRetry($container);
        $this->registerHealthCheck($container, $env);
    }

    private function registerHealthCheck(ContainerBuilder $container, string $env): void
    {
        $container->register(KafkaHealthCheck::class, KafkaHealthCheck::class)
            ->setArgument('$transports', new Reference(TransportRegistry::class))
            ->setPublic(false);

        $container->register(\Vortos\Messaging\Doctor\MessagingDoctorCheck::class, \Vortos\Messaging\Doctor\MessagingDoctorCheck::class)
            ->setPublic(false);

        $container->register(\Vortos\Messaging\Driver\Kafka\Command\KafkaTailCommand::class, \Vortos\Messaging\Driver\Kafka\Command\KafkaTailCommand::class)
            ->setArgument('$transports', new Reference(TransportRegistry::class))
            ->setArgument('$sanitizer', new Reference(PayloadSanitizerInterface::class))
            ->addTag('console.command')
            ->setPublic(true);

        if ($env === 'prod') {
            return;
        }

        $container->register(\Vortos\Messaging\Dev\TailState::class, \Vortos\Messaging\Dev\TailState::class)
            ->setShared(true)
            ->setPublic(false);

        $container->register(\Vortos\Messaging\Dev\Hook\ConsumerTailBeforeHandlerHook::class, \Vortos\Messaging\Dev\Hook\ConsumerTailBeforeHandlerHook::class)
            ->setArgument('$state', new Reference(\Vortos\Messaging\Dev\TailState::class))
            ->addTag('vortos.hook')
            ->setPublic(true);

        $container->register(\Vortos\Messaging\Dev\Hook\ConsumerTailAfterHandlerHook::class, \Vortos\Messaging\Dev\Hook\ConsumerTailAfterHandlerHook::class)
            ->setArgument('$state', new Reference(\Vortos\Messaging\Dev\TailState::class))
            ->addTag('vortos.hook')
            ->setPublic(true);

        $container->register(\Vortos\Messaging\Dev\Hook\ConsumerTailControlHook::class, \Vortos\Messaging\Dev\Hook\ConsumerTailControlHook::class)
            ->setArgument('$state', new Reference(\Vortos\Messaging\Dev\TailState::class))
            ->setArgument('$redis', new Reference(\Redis::class, ContainerInterface::NULL_ON_INVALID_REFERENCE))
            ->addTag('vortos.hook')
            ->setPublic(true);

        $container->register(\Vortos\Messaging\Dev\Hook\ConsumerTailFlushHook::class, \Vortos\Messaging\Dev\Hook\ConsumerTailFlushHook::class)
            ->setArgument('$state', new Reference(\Vortos\Messaging\Dev\TailState::class))
            ->addTag('vortos.hook')
            ->setPublic(true);

        // TailConsumerCommand is the Redis pub/sub observer — null Redis means it
        // fails at runtime with a clear message rather than failing at boot.
        $container->register(\Vortos\Messaging\Command\TailConsumerCommand::class, \Vortos\Messaging\Command\TailConsumerCommand::class)
            ->setArgument('$redis', new Reference(\Redis::class, ContainerInterface::NULL_ON_INVALID_REFERENCE))
            ->addTag('console.command')
            ->setPublic(true);
    }

    private function registerRetry(ContainerBuilder $container): void
    {
        $container->register(\Vortos\Messaging\Retry\RetryDecider::class, \Vortos\Messaging\Retry\RetryDecider::class)
            ->setAutowired(true)
            ->setPublic(false);

        $container->register(\Vortos\Messaging\Retry\RetryDelayCalculator::class, \Vortos\Messaging\Retry\RetryDelayCalculator::class)
            ->setAutowired(true)
            ->setPublic(false);
    }

    private function initializeParameters(ContainerBuilder $container): void
    {
        $defaults = [
            'vortos.event_producer_map'              => [],
            'vortos.handlers'                        => [],
            'vortos.hooks'                           => [],
            'vortos.messaging.replay_secret_default' => '',
        ];

        foreach ($defaults as $key => $value) {
            if (!$container->hasParameter($key)) {
                $container->setParameter($key, $value);
            }
        }

        $container->setParameter(
            'vortos.messaging.replay_secret',
            '%env(default:vortos.messaging.replay_secret_default:VORTOS_REPLAY_SECRET)%',
        );
    }

    private function registerInProcessBus(ContainerBuilder $container): void
    {
        $container->register(MessageBus::class, MessageBus::class)
            ->setArguments([[]])
            ->setShared(true)
            ->setPublic(false);

        $container->setAlias(MessageBusInterface::class, MessageBus::class)
            ->setPublic(false);
    }

    private function registerHooks(ContainerBuilder $container): void
    {
        $container->register('vortos.hook_locator', ServiceLocator::class)
            ->setArguments([[]])
            ->setPublic(false);

        $container->register(HookRegistry::class, HookRegistry::class)
            ->setArgument('$hooks', '%vortos.hooks%')
            ->setPublic(false);

        $container->register(HookRunner::class, HookRunner::class)
            ->setAutowired(true)
            ->setArgument('$hookLocator', new Reference('vortos.hook_locator'))
            ->setPublic(false);

        $container->register(HookMiddleware::class, HookMiddleware::class)
            ->setAutowired(true)
            ->setPublic(false);

        foreach ($this->hookAttributeMap() as $attributeClass => $hookType) {
            $container->registerAttributeForAutoconfiguration(
                $attributeClass,
                static function (ChildDefinition $definition) use ($hookType): void {
                    $definition->addTag('vortos.hook', ['type' => $hookType]);
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
            BeforeHandler::class  => HookDescriptor::BEFORE_HANDLER,
            AfterHandler::class   => HookDescriptor::AFTER_HANDLER,
        ];
    }

    private function registerDefaultDriverInterfaces(ContainerBuilder $container, array $driver): void
    {
        $producer = $driver['producer'] === KafkaProducer::class
            ? LazyKafkaProducer::class
            : $driver['producer'];

        $container->setAlias(ProducerInterface::class, $driver['producer'])
            ->setPublic(false);

        if (!empty($driver['consumer'])) {
            $container->setAlias(ConsumerInterface::class, $driver['consumer'])
                ->setPublic(false);
        }
    }

    private function registerCLICommands(ContainerBuilder $container): void
    {
        // ConsumeCommand gets explicit injection so optional TailState/Redis are wired
        // by NULL_ON_INVALID_REFERENCE (null in prod or when Redis driver is inactive).
        $container->register(\Vortos\Messaging\Command\ConsumeCommand::class, \Vortos\Messaging\Command\ConsumeCommand::class)
            ->setArgument('$consumerRunner', new Reference(ConsumerRunnerInterface::class))
            ->setArgument('$logger', new Reference(\Psr\Log\LoggerInterface::class))
            ->setArgument('$tailState', new Reference(\Vortos\Messaging\Dev\TailState::class, ContainerInterface::NULL_ON_INVALID_REFERENCE))
            ->setPublic(true)
            ->addTag('console.command');

        $commands = [
            \Vortos\Messaging\Command\OutboxRelayCommand::class,
            \Vortos\Messaging\Command\OutboxReplayCommand::class,
            \Vortos\Messaging\Command\ListOutboxCommand::class,
            \Vortos\Messaging\Command\ShowOutboxCommand::class,
            \Vortos\Messaging\Command\ListConsumersCommand::class,
            \Vortos\Messaging\Command\ListTransportsCommand::class,
            \Vortos\Messaging\Command\ListDeadLetterCommand::class,
            \Vortos\Messaging\Command\ShowDeadLetterCommand::class,
            \Vortos\Messaging\Command\ReplayDeadLetterCommand::class,
        ];

        foreach ($commands as $class) {
            $container->register($class, $class)
                ->setAutowired(true)
                ->setPublic(true)
                ->addTag('console.command');
        }

        if (class_exists(\Vortos\Docker\Worker\WorkerProcessDefinition::class)) {
            $container->register('vortos_messaging.worker.outbox_relay', \Vortos\Docker\Worker\WorkerProcessDefinition::class)
                ->setArguments([
                    'messaging-outbox-relay',
                    'php /var/www/html/bin/console vortos:outbox:relay',
                    'Relay pending messaging outbox events.',
                ])
                ->addTag('vortos.worker')
                ->setPublic(false);
        }
    }

    private function registerConsumerRunner(ContainerBuilder $container, int $defaultIdempotencyTtl): void
    {
        $container->register(ConsumerLocator::class, ConsumerLocator::class)
            ->setAutowired(true)
            ->setPublic(false);

        $container->setAlias(ConsumerLocatorInterface::class, ConsumerLocator::class)
            ->setPublic(false);

        $container->register('vortos.handler_locator', ServiceLocator::class)
            ->setArguments([[]])  // HandlerDiscoveryCompilerPass fills this
            ->addTag('container.service_locator')
            ->setPublic(false);

        $container->register(ConsumerRunner::class, ConsumerRunner::class)
            ->setAutowired(true)
            ->setAutoconfigured(true)
            ->setArgument('$handlerLocator', new Reference('vortos.handler_locator'))
            ->setArgument('$defaultIdempotencyTtl', $defaultIdempotencyTtl)
            ->setArgument('$hookRunner', new Reference(HookRunner::class))
            ->setPublic(false);

        $container->setAlias(ConsumerRunnerInterface::class, ConsumerRunner::class)
            ->setPublic(false);
    }

    private function registerEventBus(ContainerBuilder $container): void
    {
        $container->register(EventBus::class, EventBus::class)
            ->setAutowired(true)
            ->setAutoconfigured(true)
            ->setArgument('$eventProducerMap', '%vortos.event_producer_map%')
            ->setArgument('$consumerRegistry', new Reference(ConsumerRegistry::class))
            ->setPublic(false);

        $container->setAlias(EventBusInterface::class, EventBus::class)
            ->setPublic(true);
    }

    private function registerSyncProjectionDecorator(ContainerBuilder $container, string $env): void
    {
        if (!in_array($env, ['dev', 'test'], true)) {
            return;
        }

        // VORTOS_SYNC_PROJECTIONS=false opts out — useful when testing real Kafka flow in dev.
        if (($_ENV['VORTOS_SYNC_PROJECTIONS'] ?? 'true') === 'false') {
            return;
        }

        $container->register(SyncProjectionEventBusDecorator::class, SyncProjectionEventBusDecorator::class)
            ->setAutowired(false)
            ->setArgument('$inner', new Reference(EventBus::class))
            ->setArgument('$handlerRegistry', new Reference(HandlerRegistry::class))
            ->setArgument('$handlerLocator', new Reference('vortos.handler_locator'))
            ->setArgument('$logger', new Reference(\Psr\Log\LoggerInterface::class))
            ->setPublic(false);

        $container->setAlias(EventBusInterface::class, SyncProjectionEventBusDecorator::class)
            ->setPublic(true);
    }

    private function registerStandaloneEventBus(ContainerBuilder $container): void
    {
        $container->register(StandaloneEventBus::class, StandaloneEventBus::class)
            ->setArguments([
                new Reference(\Doctrine\DBAL\Connection::class),
                new Reference(EventBusInterface::class),
            ])
            ->setShared(true)
            ->setPublic(false);

        $container->setAlias(StandaloneEventBusInterface::class, StandaloneEventBus::class)
            ->setPublic(false);
    }

    private function registerKafkaDrivers(ContainerBuilder $container): void
    {
        $container->register(LazyKafkaProducer::class, LazyKafkaProducer::class)
            ->setAutowired(true)
            ->setPublic(false);

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

    private function registerDeadLetterWriter(ContainerBuilder $container, string $dlqTable): void
    {
        $container->setParameter('vortos.messaging.dlq_table', $dlqTable);

        $container->register(\Vortos\Messaging\DeadLetter\DeadLetterRepository::class, \Vortos\Messaging\DeadLetter\DeadLetterRepository::class)
            ->setAutowired(true)
            ->setArgument('$table', $dlqTable)
            ->setPublic(false);

        $container->setAlias(DeadLetterRepositoryInterface::class, \Vortos\Messaging\DeadLetter\DeadLetterRepository::class)
            ->setPublic(false);

        $container->register(NullPayloadSanitizer::class, NullPayloadSanitizer::class)
            ->setPublic(false);

        $container->setAlias(PayloadSanitizerInterface::class, NullPayloadSanitizer::class)
            ->setPublic(false);

        $container->register(DeadLetterWriter::class, DeadLetterWriter::class)
            ->setAutowired(true)
            ->setArgument('$table', $dlqTable)
            ->setArgument('$sanitizer', new Reference(PayloadSanitizerInterface::class))
            ->setPublic(false);
    }

    private function registerOutbox(ContainerBuilder $container, array $outboxConfig): void
    {
        $container->setParameter('vortos.messaging.outbox_table', $outboxConfig['table']);

        $container->register(OutboxWriter::class, OutboxWriter::class)
            ->setAutowired(true)
            ->setArgument('$table', $outboxConfig['table'])
            ->setPublic(false);

        $container->setAlias(OutboxInterface::class, OutboxWriter::class)
            ->setPublic(false);

        $container->register(OutboxPoller::class, OutboxPoller::class)
            ->setAutowired(true)
            ->setArgument('$tableName', $outboxConfig['table'])
            ->setArgument('$maxAttempts', $outboxConfig['max_attempts'])
            ->setArgument('$backoffBase', $outboxConfig['backoff_base'])
            ->setArgument('$backoffCap', $outboxConfig['backoff_cap'])
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

        // $container->register(TransactionalMiddleware::class, TransactionalMiddleware::class)
        //     ->setAutowired(true)
        //     ->setAutoconfigured(true);
        $container->register(TransactionalMiddleware::class, TransactionalMiddleware::class)
            ->setArgument('$unitOfWork', new Reference(UnitOfWorkInterface::class))
            ->setPublic(false);
    }

    private function registerMiddlewareStack(ContainerBuilder $container): void
    {
        // Core middlewares are registered here in fixed order.
        // User-defined middlewares tagged 'vortos.middleware' are appended
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
            ->setPublic(false);

        $container->register(ProducerRegistry::class, ProducerRegistry::class)
            ->setPublic(false);

        $container->register(ConsumerRegistry::class, ConsumerRegistry::class)
            ->setPublic(false);

        $container->register(HandlerRegistry::class, HandlerRegistry::class)
            ->setPublic(false);
    }

    private function registerMessagingConfigAttributes(ContainerBuilder $container): void
    {
        $container->registerAttributeForAutoconfiguration(
            MessagingConfig::class,
            static function (ChildDefinition $definition, MessagingConfig $attribute): void {
                $definition->addTag('vortos.messaging_config');
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

                $definition->addTag('vortos.event_handler', $tagAttributes);
                $definition->setPublic(true);
            }
        );

        $container->registerAttributeForAutoconfiguration(
            RegisterTransport::class,
            static function (ChildDefinition $definition, RegisterTransport $attribute) {
                $definition->addTag('vortos.messenger.transport.definition');
                $definition->setPublic(true);
            }
        );

        $container->registerAttributeForAutoconfiguration(
            AsMiddleware::class,
            static function (ChildDefinition $definition, AsMiddleware $attribute) {

                $tagAttributes = [
                    'priority' => $attribute->priority
                ];

                $definition->addTag('vortos.middleware', $tagAttributes);
                $definition->setPublic(true);
            }
        );

        $container->register('vortos.config_stub.messaging', ConfigStub::class)
            ->setArguments(['messaging', __DIR__ . '/../stubs/messaging.php'])
            ->addTag(ConfigExtension::STUB_TAG)
            ->setPublic(false);
    }
}
