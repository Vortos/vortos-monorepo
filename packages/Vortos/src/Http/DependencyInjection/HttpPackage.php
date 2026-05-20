<?php

declare(strict_types=1);

namespace Vortos\Http\DependencyInjection;

use Symfony\Component\DependencyInjection\Compiler\PassConfig;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\ExtensionInterface;
use Vortos\Foundation\Contract\PackageInterface;
use Vortos\Http\DependencyInjection\Compiler\RegisterExceptionHandlerPass;
use Vortos\Http\DependencyInjection\Compiler\RegisterMiddlewarePass;
use Vortos\Http\DependencyInjection\Compiler\RegisterTerminablePass;
use Vortos\Http\DependencyInjection\Compiler\RouteCompilerPass;

/**
 * HTTP package — Vortos kernel, routing, pipeline middleware.
 *
 * Add to Container.php first — before all other packages.
 * The HTTP kernel ('vortos') must be registered before other packages
 * add middleware that depends on it.
 */
final class HttpPackage implements PackageInterface
{
    public function getContainerExtension(): ?ExtensionInterface
    {
        return new HttpExtension();
    }

    public function build(ContainerBuilder $container): void
    {
        $container->addCompilerPass(
            new RegisterMiddlewarePass(),
            PassConfig::TYPE_BEFORE_OPTIMIZATION,
            100,
        );

        $container->addCompilerPass(
            new RegisterTerminablePass(),
            PassConfig::TYPE_BEFORE_OPTIMIZATION,
            90,
        );

        $container->addCompilerPass(
            new RegisterExceptionHandlerPass(),
            PassConfig::TYPE_BEFORE_OPTIMIZATION,
            85,
        );

        $container->addCompilerPass(
            new RouteCompilerPass(),
            PassConfig::TYPE_BEFORE_OPTIMIZATION,
            80,
        );
    }
}
