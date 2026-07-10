<?php

declare(strict_types=1);

namespace Vortos\FeatureFlagsAdmin\DependencyInjection;

use Symfony\Component\DependencyInjection\Compiler\PassConfig;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\ExtensionInterface;
use Vortos\FeatureFlagsAdmin\DependencyInjection\Compiler\RequireTwoFactorVerifierPass;
use Vortos\FeatureFlagsAdmin\DependencyInjection\Compiler\TwigExtensionCompilerPass;
use Vortos\Foundation\Contract\PackageInterface;

final class FeatureFlagsAdminPackage implements PackageInterface
{
    public function getContainerExtension(): ?ExtensionInterface
    {
        return new FeatureFlagsAdminExtension();
    }

    public function build(ContainerBuilder $container): void
    {
        $container->addCompilerPass(new TwigExtensionCompilerPass());

        // Runs after the auth TwoFactorCompilerPass (BEFORE_OPTIMIZATION priority 40) so the
        // canonical verifier alias it may publish is already visible. Negative priority keeps
        // it late in the pass order.
        $container->addCompilerPass(
            new RequireTwoFactorVerifierPass(),
            PassConfig::TYPE_BEFORE_OPTIMIZATION,
            -100,
        );
    }
}
