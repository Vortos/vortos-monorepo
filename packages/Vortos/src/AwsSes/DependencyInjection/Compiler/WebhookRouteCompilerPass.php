<?php

declare(strict_types=1);

namespace Vortos\AwsSes\DependencyInjection\Compiler;

use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Vortos\AwsSes\Webhook\SnsWebhookController;

/**
 * Tags SnsWebhookController as `vortos.api.controller` when webhooks are enabled,
 * so the Http package's RouteCompilerPass discovers and registers its route.
 */
final class WebhookRouteCompilerPass implements CompilerPassInterface
{
    public function process(ContainerBuilder $container): void
    {
        if (!$container->getParameter('vortos_aws_ses.webhooks.enabled')) {
            return;
        }

        if (!$container->hasDefinition(SnsWebhookController::class)) {
            return;
        }

        $container->getDefinition(SnsWebhookController::class)
            ->addTag('vortos.api.controller');
    }
}
