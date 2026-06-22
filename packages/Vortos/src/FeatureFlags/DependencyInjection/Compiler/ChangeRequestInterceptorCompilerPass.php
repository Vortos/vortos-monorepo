<?php

declare(strict_types=1);

namespace Vortos\FeatureFlags\DependencyInjection\Compiler;

use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Vortos\FeatureFlags\ChangeRequest\ChangeRequestInterceptorImpl;
use Vortos\FeatureFlags\Http\Management\Interceptor\ChangeRequestInterceptorInterface;

/**
 * Block 14 — upgrades the change-request interceptor from the Block 13 no-op stub to the
 * real policy-backed implementation. The dependencies live in this same package, so the
 * upgrade always applies once Block 14 is installed.
 */
final class ChangeRequestInterceptorCompilerPass implements CompilerPassInterface
{
    public function process(ContainerBuilder $container): void
    {
        if (!$container->hasDefinition(ChangeRequestInterceptorImpl::class)) {
            return;
        }

        $container->setAlias(ChangeRequestInterceptorInterface::class, ChangeRequestInterceptorImpl::class)
            ->setPublic(false);
    }
}
