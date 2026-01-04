<?php

use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpKernel\Controller\ArgumentResolver;
use Symfony\Component\HttpKernel\Controller\ContainerControllerResolver;
use Symfony\Component\HttpKernel\EventListener\RouterListener;
use Symfony\Component\Routing\Matcher\UrlMatcher;
use Symfony\Component\Routing\RequestContext;
use Symfony\Component\Routing\RouteCollection;

return static function (ContainerConfigurator $configurator) {

    $services = $configurator->services()
        ->defaults()
        ->autoconfigure()
        ->autowire();

    $services->set(RequestContext::class, RequestContext::class);

    $services->set(RequestStack::class, RequestStack::class);

    $services->set(ArgumentResolver::class, ArgumentResolver::class);

    $services->set(ContainerControllerResolver::class, ContainerControllerResolver::class)
        ->args([new Reference('service_container')]);

    $services->set(RouteCollection::class, RouteCollection::class)->synthetic(true);
    
    $services->set(UrlMatcher::class, UrlMatcher::class)
        ->args([new Reference(RouteCollection::class), new Reference(RequestContext::class)]);

    $services->set(RouterListener::class, RouterListener::class)
        ->args([new Reference(UrlMatcher::class), new Reference(RequestStack::class)])
        ->tag('kernel.event_subscriber');

};