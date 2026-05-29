<?php

declare(strict_types=1);

namespace Vortos\Paddle\DependencyInjection\Compiler;

use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Vortos\Paddle\Webhook\PaddleWebhookController;

/**
 * Tags PaddleWebhookController as `vortos.api.controller` when webhooks are enabled,
 * so the Http package's RouteCompilerPass discovers and registers its route.
 */
final class WebhookRouteCompilerPass implements CompilerPassInterface
{
    public function process(ContainerBuilder $container): void
    {
        if (!$container->getParameter('vortos_paddle.webhooks.enabled')) {
            return;
        }

        if (!$container->hasDefinition(PaddleWebhookController::class)) {
            return;
        }

        $container->getDefinition(PaddleWebhookController::class)
            ->addTag('vortos.api.controller');
    }
}
