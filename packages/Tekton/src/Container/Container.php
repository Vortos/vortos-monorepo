<?php

use Fortizan\Tekton\EventListener\GoogleListener;
use Fortizan\Tekton\EventListener\StringResponseListener;
use Fortizan\Tekton\Http\Kernal;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\ChildDefinition;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\PhpFileLoader;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpKernel\Controller\ArgumentResolver;
use Symfony\Component\HttpKernel\Controller\ContainerControllerResolver;
use Symfony\Component\HttpKernel\EventListener\ErrorListener;
use Symfony\Component\HttpKernel\EventListener\ResponseListener;
use Symfony\Component\HttpKernel\EventListener\RouterListener;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\DependencyInjection\MessengerPass;
use Symfony\Component\Routing\Matcher\UrlMatcher;
use Symfony\Component\Routing\RequestContext;
use Symfony\Component\Routing\RouteCollection;

$container = new ContainerBuilder();

$loader = new PhpFileLoader($container, new FileLocator(__DIR__ . '/../../config'));
$loader->load('services.php');

$container->registerAttributeForAutoconfiguration(
    AsMessageHandler::class,
    static function (ChildDefinition $definition, AsMessageHandler $attribute) {
        $definition->addTag('messenger.message_handler', [
            'bus' => $attribute->bus,
            'from_transport' => $attribute->fromTransport,
            'handles' => $attribute->handles,
            'method' => $attribute->method,
            'priority' => $attribute->priority,
            'sign' => $attribute->sign
        ]);
    }
);

$container->addCompilerPass(new MessengerPass());


// -----------------------------------------------------------------------------------

$container->setParameter('charset', 'UTF-8');
$container->setParameter('charset', 'ISO-8859-1');

$container->register('context', RequestContext::class);
$container->register('request_stack', RequestStack::class);
$container->register('argument_resolver', ArgumentResolver::class);

$container->register('controller_resolver', ContainerControllerResolver::class)
    ->setArguments([new Reference('service_container')]);


$container->register('routes', RouteCollection::class)->setSynthetic(true);

$container->register('matcher', UrlMatcher::class)->setArguments([new Reference('routes'), new Reference('context')]);

$container->register('listener.router', RouterListener::class)
    ->setArguments([new Reference('matcher'), new Reference('request_stack')]);

$container->register('listener.response', ResponseListener::class)
    ->setArguments(['%charset%']);

$container->register('listener.exception', ErrorListener::class)
    ->setArguments(['Fortizan\Tekton\Controller\ErrorController::handle']);

$container->register('listener.google', GoogleListener::class);
$container->register('listener.string_response_listener', StringResponseListener::class);

$container->register('dispatcher', EventDispatcher::class)
    ->addMethodCall('addSubscriber', [new Reference('listener.router')])
    ->addMethodCall('addSubscriber', [new Reference('listener.response')])
    ->addMethodCall('addSubscriber', [new Reference('listener.exception')])
    ->addMethodCall('addSubscriber', [new Reference('listener.google')])
    ->addMethodCall('addSubscriber', [new Reference('listener.string_response_listener')]);

$container->register('framework', Kernal::class)->setPublic(true)->setArguments([
    new Reference('dispatcher'),
    new Reference('controller_resolver'),
    new Reference('request_stack'),
    new Reference('argument_resolver'),
]);


return $container;
