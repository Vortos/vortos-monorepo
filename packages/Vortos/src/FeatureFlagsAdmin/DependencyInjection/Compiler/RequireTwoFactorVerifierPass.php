<?php

declare(strict_types=1);

namespace Vortos\FeatureFlagsAdmin\DependencyInjection\Compiler;

use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Vortos\Auth\TwoFactor\Contract\TwoFactorVerifierInterface;
use Vortos\FeatureFlagsAdmin\Http\Middleware\AdminAuthMiddleware;

/**
 * Fail-fast guard for the admin console's 2FA step-up.
 *
 * When the console is enabled with `feature_flags_admin.require_2fa = true`, its
 * {@see AdminAuthMiddleware} enforces a 2FA-verified session — and denies fail-closed if
 * no {@see TwoFactorVerifierInterface} is wired. That deny is safe but only surfaces at
 * request time (a superadmin mysteriously locked out in prod). This pass turns that latent
 * misconfiguration into an immediate, actionable container-build error.
 *
 * Runs after {@see \Vortos\Auth\TwoFactor\Compiler\TwoFactorCompilerPass}, which publishes a
 * canonical interface alias for the single-implementation and explicitly-bound cases — so
 * this only trips when 2FA is genuinely unsatisfiable (no verifier at all, or several with
 * no canonical binding).
 */
final class RequireTwoFactorVerifierPass implements CompilerPassInterface
{
    public function process(ContainerBuilder $container): void
    {
        // Console disabled ⇒ middleware not registered ⇒ nothing to guard.
        if (!$container->hasDefinition(AdminAuthMiddleware::class)) {
            return;
        }

        $require2fa = $container->hasParameter('feature_flags_admin.require_2fa')
            && (bool) $container->getParameter('feature_flags_admin.require_2fa');

        if (!$require2fa) {
            return;
        }

        $resolvable = $container->hasAlias(TwoFactorVerifierInterface::class)
            || $container->hasDefinition(TwoFactorVerifierInterface::class);

        if (!$resolvable) {
            throw new \RuntimeException(sprintf(
                'The feature-flags admin console is configured with feature_flags_admin.require_2fa = true, but no '
                . '%s could be resolved. Register a verifier (a single implementation is wired automatically), or — '
                . 'if several implementations exist — bind the interface to your canonical one explicitly, e.g. '
                . '$services->alias(%s::class, YourVerifier::class).',
                TwoFactorVerifierInterface::class,
                TwoFactorVerifierInterface::class,
            ));
        }
    }
}
