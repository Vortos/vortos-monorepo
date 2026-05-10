<?php

declare(strict_types=1);

namespace Vortos\Security\DependencyInjection\Compiler;

use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * Currently a no-op pass — SecurityHeadersMiddleware reads its config as a frozen
 * array injected at extension load time (no per-route variation for headers).
 *
 * Reserved for future compile-time CSP nonce pre-computation or per-route overrides.
 */
final class SecurityHeadersCompilerPass implements CompilerPassInterface
{
    public function process(ContainerBuilder $container): void {}
}
