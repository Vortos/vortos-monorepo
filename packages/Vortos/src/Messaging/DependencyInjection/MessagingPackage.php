<?php

declare(strict_types=1);

namespace Vortos\Messaging\DependencyInjection;

use Symfony\Component\DependencyInjection\Compiler\PassConfig;
use Vortos\Foundation\Contract\PackageInterface;
use Vortos\Messaging\DependencyInjection\Compiler\HandlerDiscoveryCompilerPass;
use Vortos\Messaging\DependencyInjection\Compiler\HookDiscoveryCompilerPass;
use Vortos\Messaging\DependencyInjection\Compiler\MessagingConfigCompilerPass;
use Vortos\Messaging\DependencyInjection\Compiler\MiddlewareCompilerPass;
use Vortos\Messaging\DependencyInjection\Compiler\TransportRegistryCompilerPass;
use Vortos\Messaging\DependencyInjection\MessagingExtension;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\ExtensionInterface;
use Vortos\Messaging\DependencyInjection\Compiler\HandlerLocatorPass;
use Vortos\Messaging\DependencyInjection\Compiler\ProjectionDiscoveryCompilerPass;
use Vortos\Messaging\DependencyInjection\Compiler\ReplaySecretCompilerPass;

final class MessagingPackage implements PackageInterface
{
    public function getContainerExtension(): ?ExtensionInterface
    {
        return new MessagingExtension();
    }

    public function build(ContainerBuilder $container): void
    {
        $container->addCompilerPass(
            new MessagingConfigCompilerPass(),
            PassConfig::TYPE_BEFORE_OPTIMIZATION,
            100,  // first — reads messaging config, registers transports/producers/consumers
        );
        $container->addCompilerPass(
            new HandlerDiscoveryCompilerPass(),
            PassConfig::TYPE_BEFORE_OPTIMIZATION,
            90,   // discovers #[AsEventHandler]
        );
        $container->addCompilerPass(
            new ProjectionDiscoveryCompilerPass(),
            PassConfig::TYPE_BEFORE_OPTIMIZATION,
            85,   // discovers #[AsProjectionHandler]
        );
        $container->addCompilerPass(
            new HandlerLocatorPass(),
            PassConfig::TYPE_BEFORE_OPTIMIZATION,
            80,   // builds handler_locator from final vortos.handlers — must be after all discovery
        );
        $container->addCompilerPass(
            new TransportRegistryCompilerPass(),
            PassConfig::TYPE_BEFORE_OPTIMIZATION,
            70,   // registers transport definitions into registry
        );
        $container->addCompilerPass(
            new MiddlewareCompilerPass(),
            PassConfig::TYPE_BEFORE_OPTIMIZATION,
            60,   // orders and wires middleware stack
        );
        $container->addCompilerPass(
            new HookDiscoveryCompilerPass(),
            PassConfig::TYPE_BEFORE_OPTIMIZATION,
            50,   // discovers lifecycle hooks — after handlers so hook registry is complete
        );
        $container->addCompilerPass(
            new ReplaySecretCompilerPass(),
            PassConfig::TYPE_BEFORE_OPTIMIZATION,
            45,   // injects replay secret into ConsumerRunner and ReplayDeadLetterCommand
        );
    }
}
