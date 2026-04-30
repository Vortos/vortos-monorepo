<?php

declare(strict_types=1);

namespace Vortos\Http\DependencyInjection;

use Symfony\Component\DependencyInjection\ChildDefinition;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\Extension;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpKernel\Controller\ArgumentResolver;
use Symfony\Component\HttpKernel\Controller\ContainerControllerResolver;
use Symfony\Component\HttpKernel\EventListener\ErrorListener;
use Symfony\Component\HttpKernel\EventListener\ResponseListener;
use Symfony\Component\HttpKernel\EventListener\RouterListener;
use Symfony\Component\Routing\Matcher\UrlMatcher;
use Symfony\Component\Routing\RequestContext;
use Symfony\Component\Routing\RouteCollection;
use Vortos\Cqrs\Validation\VortosValidator;
use Vortos\Http\Attribute\ApiController;
use Vortos\Http\Controller\ErrorController;
use Vortos\Http\EventListener\ValidationExceptionListener;
use Vortos\Http\Factory\ArgumentResolverFactory;
use Vortos\Http\Kernel;
use Vortos\Http\Request\RequestDtoArgumentResolver;

final class HttpExtension extends Extension
{
    public function getAlias(): string
    {
        return 'vortos_http';
    }

    public function load(array $configs, ContainerBuilder $container): void
    {
        $charset = $container->hasParameter('charset') ? $container->getParameter('charset') : 'UTF-8';

        $container->register(EventDispatcher::class, EventDispatcher::class)
            ->setShared(true)->setPublic(true);

        $container->register(RequestStack::class, RequestStack::class)
            ->setShared(true)->setPublic(true);

        $container->register(RequestContext::class, RequestContext::class)
            ->setShared(true)->setPublic(true);

        $container->register(RouteCollection::class, RouteCollection::class)
            ->setSynthetic(true)->setPublic(true);

        $container->register(UrlMatcher::class, UrlMatcher::class)
            ->setArguments([new Reference(RouteCollection::class), new Reference(RequestContext::class)])
            ->setShared(true)->setPublic(true);

        $container->register(RouterListener::class, RouterListener::class)
            ->setArguments([new Reference(UrlMatcher::class), new Reference(RequestStack::class)])
            ->setAutowired(false)->setAutoconfigured(false)
            ->addTag('kernel.event_subscriber')
            ->setShared(true)->setPublic(false);

        $container->register(ErrorController::class, ErrorController::class)
            ->setArguments([$container->hasParameter('kernel.debug') ? '%kernel.debug%' : false])
            ->setShared(true)->setPublic(true);

        $container->register(ResponseListener::class, ResponseListener::class)
            ->setArguments([$charset])
            ->addTag('kernel.event_subscriber')
            ->setShared(true)->setPublic(false);

        $container->register(ErrorListener::class, ErrorListener::class)
            ->setArguments([ErrorController::class])
            ->addTag('kernel.event_subscriber')
            ->setShared(true)->setPublic(false);

        $container->register(ContainerControllerResolver::class, ContainerControllerResolver::class)
            ->setArguments([new Reference('service_container')])
            ->setShared(true)->setPublic(false);

        // VortosValidator — shared instance
        $container->register(VortosValidator::class, VortosValidator::class)
            ->setShared(true)->setPublic(false);

        // 1. Register your DTO resolver (Tags are no longer needed)
        $container->register(RequestDtoArgumentResolver::class, RequestDtoArgumentResolver::class)
            ->setArguments([new Reference(VortosValidator::class)])
            ->setShared(true)->setPublic(false);

        // 2. Register ArgumentResolver using the Factory
        $container->register(ArgumentResolver::class, ArgumentResolver::class)
            ->setFactory([ArgumentResolverFactory::class, 'create'])
            ->setArguments([new Reference(RequestDtoArgumentResolver::class)])
            ->setShared(true)->setPublic(false);

        // ValidationExceptionListener
        $container->register(ValidationExceptionListener::class, ValidationExceptionListener::class)
            ->addTag('kernel.event_subscriber')
            ->setShared(true)->setPublic(false);

        $container->register('vortos', Kernel::class)
            ->setArguments([
                new Reference(EventDispatcher::class),
                new Reference(ContainerControllerResolver::class),
                new Reference(RequestStack::class),
                new Reference(ArgumentResolver::class),
            ])
            ->setShared(true)->setPublic(true);

        $container->registerForAutoconfiguration(EventSubscriberInterface::class)
            ->addTag('kernel.event_subscriber');

        $container->registerAttributeForAutoconfiguration(
            ApiController::class,
            static function (ChildDefinition $definition, ApiController $attribute): void {
                $definition->setPublic(true);
                $definition->addTag('vortos.api.controller');
            },
        );
    }
}
