<?php

declare(strict_types=1);

namespace Vortos\Http\DependencyInjection;

use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ChildDefinition;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\Extension;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Routing\Matcher\UrlMatcher;
use Symfony\Component\Routing\RequestContext;
use Symfony\Component\Routing\RouteCollection;
use Vortos\Cqrs\Validation\VortosValidator;
use Vortos\Http\Attribute\AsController;
use Vortos\Http\Contract\ArgumentValueResolverInterface;
use Vortos\Http\Contract\ExceptionHandlerInterface;
use Vortos\Http\Contract\MiddlewareInterface;
use Vortos\Http\Contract\TerminableMiddlewareInterface;
use Vortos\Http\Controller\ArgumentResolver;
use Vortos\Http\Controller\ControllerResolver;
use Vortos\Http\Controller\ErrorController;
use Vortos\Http\Controller\Resolver\BackedEnumValueResolver;
use Vortos\Http\Controller\Resolver\DefaultValueResolver;
use Vortos\Http\Controller\Resolver\RequestAttributeValueResolver;
use Vortos\Http\Controller\Resolver\RequestDtoValueResolver;
use Vortos\Http\Controller\Resolver\RequestValueResolver;
use Vortos\Http\Controller\Resolver\VariadicValueResolver;
use Vortos\Http\EventListener\TracingMiddleware;
use Vortos\Http\EventListener\ValidationExceptionHandler;
use Vortos\Http\Kernel;
use Vortos\Http\Pipeline\Pipeline;
use Vortos\Http\Routing\Router;
use Vortos\Tracing\Contract\TracingInterface;

final class HttpExtension extends Extension
{
    public function getAlias(): string
    {
        return 'vortos_http';
    }

    public function load(array $configs, ContainerBuilder $container): void
    {
        // Trusted proxy / host security parameters — apps override in config
        if (!$container->hasParameter('vortos.trusted_proxies')) {
            $container->setParameter('vortos.trusted_proxies', []);
        }
        if (!$container->hasParameter('vortos.trusted_hosts')) {
            $container->setParameter('vortos.trusted_hosts', []);
        }

        // Tracing trust parameter — TracingExtension (order 50) overrides this
        if (!$container->hasParameter('vortos.tracing.trust_remote_context')) {
            $container->setParameter('vortos.tracing.trust_remote_context', false);
        }

        // --- Core HTTP infrastructure ---

        $container->register(RequestStack::class, RequestStack::class)
            ->setShared(true)->setPublic(true);

        $container->register(RequestContext::class, RequestContext::class)
            ->setShared(true)->setPublic(true);

        $container->register(RouteCollection::class, RouteCollection::class)
            ->setSynthetic(true)->setPublic(true);

        $container->register(UrlMatcher::class, UrlMatcher::class)
            ->setArguments([new Reference(RouteCollection::class), new Reference(RequestContext::class)])
            ->setShared(true)->setPublic(true);

        $container->register(Router::class, Router::class)
            ->setArguments([new Reference(UrlMatcher::class), new Reference(RequestContext::class)])
            ->setShared(true)->setPublic(false);

        $container->register(ControllerResolver::class, ControllerResolver::class)
            ->setArguments([new Reference('service_container')])
            ->setShared(true)->setPublic(false);

        // --- Argument resolvers (ordered by priority tag, highest first) ---

        $container->register(VortosValidator::class, VortosValidator::class)
            ->setShared(true)->setPublic(false);

        $container->register(RequestDtoValueResolver::class, RequestDtoValueResolver::class)
            ->setArguments([new Reference(VortosValidator::class)])
            ->addTag('vortos.argument_value_resolver', ['priority' => 100])
            ->setShared(true)->setPublic(false);

        $container->register(RequestValueResolver::class, RequestValueResolver::class)
            ->addTag('vortos.argument_value_resolver', ['priority' => 90])
            ->setShared(true)->setPublic(false);

        $container->register(RequestAttributeValueResolver::class, RequestAttributeValueResolver::class)
            ->addTag('vortos.argument_value_resolver', ['priority' => 80])
            ->setShared(true)->setPublic(false);

        $container->register(BackedEnumValueResolver::class, BackedEnumValueResolver::class)
            ->addTag('vortos.argument_value_resolver', ['priority' => 70])
            ->setShared(true)->setPublic(false);

        $container->register(VariadicValueResolver::class, VariadicValueResolver::class)
            ->addTag('vortos.argument_value_resolver', ['priority' => 10])
            ->setShared(true)->setPublic(false);

        $container->register(DefaultValueResolver::class, DefaultValueResolver::class)
            ->addTag('vortos.argument_value_resolver', ['priority' => 0])
            ->setShared(true)->setPublic(false);

        // ArgumentResolver — resolvers list injected by RegisterArgumentResolversPass (or inline here)
        // We build the ordered list directly since the set is fixed at boot.
        $container->register(ArgumentResolver::class, ArgumentResolver::class)
            ->setArguments([[
                new Reference(RequestDtoValueResolver::class),
                new Reference(RequestValueResolver::class),
                new Reference(RequestAttributeValueResolver::class),
                new Reference(BackedEnumValueResolver::class),
                new Reference(VariadicValueResolver::class),
                new Reference(DefaultValueResolver::class),
            ]])
            ->setShared(true)->setPublic(false);

        // --- Pipeline (middleware list injected by RegisterMiddlewarePass) ---

        $container->register(Pipeline::class, Pipeline::class)
            ->setArguments([[]])  // overwritten by RegisterMiddlewarePass
            ->setShared(true)->setPublic(false);

        // --- Exception handlers ---

        $container->register(ValidationExceptionHandler::class, ValidationExceptionHandler::class)
            ->addTag('vortos.exception_handler', ['priority' => 64])
            ->setShared(true)->setPublic(false);

        $container->register(ErrorController::class, ErrorController::class)
            ->setArguments([
                $container->hasParameter('kernel.debug') ? '%kernel.debug%' : false,
                new Reference(LoggerInterface::class, ContainerBuilder::NULL_ON_INVALID_REFERENCE),
            ])
            ->addTag('vortos.exception_handler', ['priority' => 0])
            ->setShared(true)->setPublic(true);

        // --- Kernel ---

        $container->register(Kernel::class, Kernel::class)
            ->setArgument('$router', new Reference(Router::class))
            ->setArgument('$controllerResolver', new Reference(ControllerResolver::class))
            ->setArgument('$argumentResolver', new Reference(ArgumentResolver::class))
            ->setArgument('$pipeline', new Reference(Pipeline::class))
            ->setArgument('$requestStack', new Reference(RequestStack::class))
            ->setArgument('$exceptionHandlers', [])   // overwritten by RegisterExceptionHandlerPass
            ->setArgument('$terminableMiddleware', []) // overwritten by RegisterTerminablePass
            ->setArgument('$logger', new Reference(LoggerInterface::class, ContainerBuilder::NULL_ON_INVALID_REFERENCE))
            ->setShared(true)->setPublic(true);

        // Public alias: 'vortos' → Kernel
        $container->setAlias('vortos', Kernel::class)->setPublic(true);

        // --- TracingMiddleware ---

        $container->register(TracingMiddleware::class, TracingMiddleware::class)
            ->setArgument('$tracer', new Reference(TracingInterface::class))
            ->setArgument('$trustRemoteContext', '%vortos.tracing.trust_remote_context%')
            ->addTag('vortos.http_middleware')
            ->setShared(true)->setPublic(false);

        // --- Autoconfiguration ---

        $container->registerForAutoconfiguration(MiddlewareInterface::class)
            ->addTag('vortos.http_middleware');

        $container->registerForAutoconfiguration(TerminableMiddlewareInterface::class)
            ->addTag('vortos.terminable');

        $container->registerForAutoconfiguration(ExceptionHandlerInterface::class)
            ->addTag('vortos.exception_handler');

        $container->registerForAutoconfiguration(ArgumentValueResolverInterface::class)
            ->addTag('vortos.argument_value_resolver');

        $container->registerAttributeForAutoconfiguration(
            AsController::class,
            static function (ChildDefinition $definition, AsController $attribute): void {
                $definition->setPublic(true);
                $definition->addTag('vortos.api.controller');
            },
        );
    }
}
