<?php

namespace Fortizan\Tekton\DependencyInjection;

use Fortizan\Tekton\Attribute\ApiController;
use Fortizan\Tekton\Bus\Command\Attribute\CommandHandler;
use Fortizan\Tekton\Bus\Command\Attribute\AsCommand;
use Fortizan\Tekton\Bus\Event\Attribute\AsEventHandler;
use Fortizan\Tekton\Bus\Projection\Attribute\ProjectionHandler;
use Fortizan\Tekton\Bus\Query\Attribute\AsQuery;
use Fortizan\Tekton\Bus\Query\Attribute\QueryHandler;
use Fortizan\Tekton\Messaging\Attribute\RegisterTransport;
use Monolog\Level;
use ReflectionMethod;
use Reflector;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\ChildDefinition;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\Extension;
use Symfony\Component\DependencyInjection\Loader\PhpFileLoader;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

class TektonExtension extends Extension
{
    public function load(array $configs, ContainerBuilder $container): void
    {
        $loader = new PhpFileLoader($container, new FileLocator(__DIR__ . '/../../config'));
        $loader->load('services.php');

        $this->registerCqrsAttributes($container);
        $this->registerMessengerAttributes($container);
        $this->registerHttpAttributes($container);
        $this->configureMonolog($container);
        $this->registerEventSubscribers($container);
        $this->registerProjectionAttributes($container);
        $this->registerMessagingAttributes($container);
    }

    private function configureMonolog(ContainerBuilder $container): void
    {
        if (!$container->has('monolog.handler.main')) {
            return;
        }

        $streamHandler = $container->findDefinition('monolog.handler.main');

        if ($container->getParameter('kernel.env') === 'dev') {

            $streamHandler->addMethodCall('setFormatter', [new Reference('monolog.formatter.line')]);
            $streamHandler->addArgument('%kernel.log_path%/%kernel.env%.log');
            $streamHandler->addArgument(Level::Debug);
        } else {

            $streamHandler->addMethodCall('setFormatter', [new Reference('monolog.formatter.json')]);
            $streamHandler->addArgument('php://stderr');
            $streamHandler->addArgument(Level::Error);
        }
    }

    private function registerCqrsAttributes(ContainerBuilder $container): void
    {
        $container->registerAttributeForAutoconfiguration(
            AsCommand::class,
            static function (ChildDefinition $definition, AsCommand $attribute) {
                $definition->addTag('tekton.command', [
                    'transport' => $attribute->transport,
                    'topic' => $attribute->topic
                ]);
            }
        );

        $container->registerAttributeForAutoconfiguration(
            CommandHandler::class,
            static function (ChildDefinition $definition, CommandHandler $attribute) {
                $definition->addTag('tekton.command.handler', [
                    'bus' => $attribute->bus,
                    'from_transport' => $attribute->fromTransport
                ]);
            }
        );

        $container->registerAttributeForAutoconfiguration(
            AsQuery::class,
            static function (ChildDefinition $definition, AsQuery $attribute) {
                $definition->addTag('tekton.query', [
                    'transport' => $attribute->transport,
                    'topic' => $attribute->topic
                ]);
            }
        );

        $container->registerAttributeForAutoconfiguration(
            QueryHandler::class,
            static function (ChildDefinition $definition, QueryHandler $attribute) {
                $definition->addTag('tekton.query.handler', [
                    'bus' => $attribute->bus
                ]);
            }
        );
    }

    private function registerMessengerAttributes(ContainerBuilder $container): void
    {
        $container->registerAttributeForAutoconfiguration(
            AsMessageHandler::class,
            static function (ChildDefinition $definition, AsMessageHandler $attribute, Reflector $reflector) {

                $tagAttributes = [
                    'bus' => $attribute->bus,
                    'from_transport' => $attribute->fromTransport,
                    'handles' => $attribute->handles,
                    'method' => $attribute->method,
                    'priority' => $attribute->priority,
                    'sign' => $attribute->sign
                ];

                if ($reflector instanceof ReflectionMethod) {
                    $tagAttributes['method'] = $reflector->getName();
                }

                $definition->addTag('messenger.message_handler', $tagAttributes);
            }
        );
    }

    private function registerHttpAttributes(ContainerBuilder $container): void
    {
        $container->registerAttributeForAutoconfiguration(
            ApiController::class,
            static function (ChildDefinition $definition, ApiController $attribute) {
                $definition->setPublic(true);
                $definition->addTag('tekton.api.controller');
            }
        );
    }

    private function registerEventSubscribers(ContainerBuilder $container): void
    {
        $container->registerForAutoconfiguration(EventSubscriberInterface::class)
            ->addTag('kernel.event_subscriber');
        // ->setPublic(true);
    }

    private function registerProjectionAttributes(ContainerBuilder $container): void
    {
        $container->registerAttributeForAutoconfiguration(
            ProjectionHandler::class,
            static function (ChildDefinition $definition, ProjectionHandler $attribute, Reflector $reflector) { //without reflector attributes on methods aint read, only classes

                $tagAttributes = [
                    'bus' => $attribute->bus,
                    'from_transport' => $attribute->fromTransport,
                    'method' => $attribute->method,
                    'priority' => $attribute->priority
                ];

                if ($reflector instanceof ReflectionMethod) {
                    $tagAttributes['method'] = $reflector->getName();
                }

                $definition->setPublic(true);
                $definition->addTag('tekton.projection.handler', $tagAttributes);
            }
        );
    }

    public function registerMessagingAttributes(ContainerBuilder $container):void
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

                $definition->addTag('tekton.event.handler', $tagAttributes);
                $definition->setPublic(true);
            }
        );

        $container->registerAttributeForAutoconfiguration(
            RegisterTransport::class, 
            static function (ChildDefinition $definition, RegisterTransport $attribute){
                $definition->addTag('tekton.messenger.transport.definition');
                $definition->setPublic(true);
            }
        );
    }
}
