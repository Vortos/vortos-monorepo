<?php

declare(strict_types=1);

namespace Vortos\Paddle\DependencyInjection\Compiler;

use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;
use Vortos\Paddle\Webhook\PaddleWebhookDispatcher;

final class WebhookHandlerDiscoveryPass implements CompilerPassInterface
{
    public function process(ContainerBuilder $container): void
    {
        if (!$container->hasDefinition(PaddleWebhookDispatcher::class)) {
            return;
        }

        $handlers = [];
        foreach ($container->findTaggedServiceIds('vortos_paddle.webhook_handler') as $id => $_tags) {
            $handlers[] = new Reference($id);
        }

        $container->getDefinition(PaddleWebhookDispatcher::class)
            ->setArgument('$handlers', $handlers);
    }
}
