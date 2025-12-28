<?php

namespace Fortizan\Tekton\DependencyInjection;

use Fortizan\Tekton\Attribute\ApiController;
use Fortizan\Tekton\Bus\Command\Attribute\CommandHandler;
use Fortizan\Tekton\Bus\Query\Attribute\QueryHandler;
use Monolog\Level;
use ReflectionMethod;
use Reflector;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\ChildDefinition;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\Extension;
use Symfony\Component\DependencyInjection\Loader\PhpFileLoader;
use Symfony\Component\DependencyInjection\Reference;
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
            CommandHandler::class,
            static function (ChildDefinition $definition, CommandHandler $attribute) {
                $definition->addTag('tekton.command.handler', [
                    'bus' => $attribute->bus,
                    'from_transport' => $attribute->fromTransport
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

                if($reflector instanceof ReflectionMethod){
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
}
