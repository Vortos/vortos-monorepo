<?php

declare(strict_types=1);

namespace Vortos\Security\DependencyInjection;

use Symfony\Component\DependencyInjection\Compiler\PassConfig;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\ExtensionInterface;
use Vortos\Foundation\Contract\PackageInterface;
use Vortos\Security\DependencyInjection\Compiler\CsrfCompilerPass;
use Vortos\Security\DependencyInjection\Compiler\EncryptionCompilerPass;
use Vortos\Security\DependencyInjection\Compiler\IpFilterCompilerPass;
use Vortos\Security\DependencyInjection\Compiler\RequestSignatureCompilerPass;
use Vortos\Security\DependencyInjection\Compiler\SecurityHeadersCompilerPass;

final class SecurityPackage implements PackageInterface
{
    public function getContainerExtension(): ?ExtensionInterface
    {
        return new SecurityExtension();
    }

    public function build(ContainerBuilder $container): void
    {
        $container->addCompilerPass(new SecurityHeadersCompilerPass(), PassConfig::TYPE_BEFORE_OPTIMIZATION, 40);
        $container->addCompilerPass(new IpFilterCompilerPass(), PassConfig::TYPE_BEFORE_OPTIMIZATION, 40);
        $container->addCompilerPass(new CsrfCompilerPass(), PassConfig::TYPE_BEFORE_OPTIMIZATION, 40);
        $container->addCompilerPass(new RequestSignatureCompilerPass(), PassConfig::TYPE_BEFORE_OPTIMIZATION, 40);
        $container->addCompilerPass(new EncryptionCompilerPass(), PassConfig::TYPE_BEFORE_OPTIMIZATION, 40);
    }
}
