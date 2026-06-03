<?php

declare(strict_types=1);

namespace Vortos\AwsSes\DependencyInjection;

use Symfony\Component\DependencyInjection\Compiler\PassConfig;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\ExtensionInterface;
use Vortos\Foundation\Contract\PackageInterface;
use Vortos\AwsSes\DependencyInjection\Compiler\BounceHandlerDiscoveryPass;
use Vortos\AwsSes\DependencyInjection\Compiler\ComplaintHandlerDiscoveryPass;
use Vortos\AwsSes\DependencyInjection\Compiler\MiddlewareCompilerPass;

/**
 * SES package.
 *
 * Registers compiler passes in priority order:
 *
 *   80 — MiddlewareCompilerPass        discovers #[AsEmailMiddleware], builds ordered stack
 *   70 — BounceHandlerDiscoveryPass    discovers #[AsBounceHandler]
 *   70 — ComplaintHandlerDiscoveryPass discovers #[AsComplaintHandler]
 *
 * ## Load order in Container.php
 *
 * AwsSesPackage must be registered AFTER:
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
 *       new AwsSesPackage(),
 *   ];
 */
final class AwsSesPackage implements PackageInterface
{
    public function getContainerExtension(): ?ExtensionInterface
    {
        return new AwsSesExtension();
    }

    public function build(ContainerBuilder $container): void
    {
        $container->addCompilerPass(new MiddlewareCompilerPass(), PassConfig::TYPE_BEFORE_OPTIMIZATION, 80);
        $container->addCompilerPass(new BounceHandlerDiscoveryPass(), PassConfig::TYPE_BEFORE_OPTIMIZATION, 70);
        $container->addCompilerPass(new ComplaintHandlerDiscoveryPass(), PassConfig::TYPE_BEFORE_OPTIMIZATION, 70);
    }
}
