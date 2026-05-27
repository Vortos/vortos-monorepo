<?php

declare(strict_types=1);

namespace Vortos\Ses\DependencyInjection;

use Symfony\Component\DependencyInjection\Compiler\PassConfig;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\ExtensionInterface;
use Vortos\Foundation\Contract\PackageInterface;
use Vortos\Ses\DependencyInjection\Compiler\BounceHandlerDiscoveryPass;
use Vortos\Ses\DependencyInjection\Compiler\ComplaintHandlerDiscoveryPass;
use Vortos\Ses\DependencyInjection\Compiler\MiddlewareCompilerPass;
use Vortos\Ses\DependencyInjection\Compiler\WebhookRouteCompilerPass;

/**
 * SES package.
 *
 * Registers compiler passes in priority order:
 *
 *   80 — MiddlewareCompilerPass      discovers #[AsEmailMiddleware], builds ordered stack
 *   70 — BounceHandlerDiscoveryPass  discovers #[AsBounceHandler]
 *   70 — ComplaintHandlerDiscoveryPass discovers #[AsComplaintHandler]
 *   60 — WebhookRouteCompilerPass    tags SnsWebhookController when webhooks.enabled=true
 *
 * ## Load order in Container.php
 *
 * SesPackage must be registered AFTER:
 *   - CachePackage   (SnsSignatureVerifier caches SNS certificates via CacheInterface)
 *   - LoggerPackage  (LogMailer and middleware use LoggerInterface)
 *   - TracingPackage (TracingMiddleware wraps sends with OTel spans)
 *   - PersistenceDbalPackage (EmailOutboxWriter uses shared DBAL Connection)
 *
 * Example:
 *   $packages = [
 *       new CachePackage(),
 *       new LoggerPackage(),
 *       new TracingPackage(),
 *       new PersistenceDbalPackage(),
 *       new SesPackage(),
 *   ];
 */
final class SesPackage implements PackageInterface
{
    public function getContainerExtension(): ?ExtensionInterface
    {
        return new SesExtension();
    }

    public function build(ContainerBuilder $container): void
    {
        $container->addCompilerPass(new MiddlewareCompilerPass(), PassConfig::TYPE_BEFORE_OPTIMIZATION, 80);
        $container->addCompilerPass(new BounceHandlerDiscoveryPass(), PassConfig::TYPE_BEFORE_OPTIMIZATION, 70);
        $container->addCompilerPass(new ComplaintHandlerDiscoveryPass(), PassConfig::TYPE_BEFORE_OPTIMIZATION, 70);
        $container->addCompilerPass(new WebhookRouteCompilerPass(), PassConfig::TYPE_BEFORE_OPTIMIZATION, 60);
    }
}
