<?php

declare(strict_types=1);

namespace Vortos\FeatureFlags\Http;

use Symfony\Component\HttpFoundation\Request;
use Vortos\Auth\Identity\CurrentUserProvider;
use Vortos\FeatureFlags\FlagContext;

/**
 * Resolves flag context from the authenticated user identity.
 *
 * Provides userId and roles to the flag evaluator automatically.
 * For extra attributes (plan, region, etc.) override this:
 *
 *   final class AppFlagContextResolver implements FlagContextResolverInterface
 *   {
 *       public function __construct(private readonly CurrentUserProvider $currentUser) {}
 *
 *       public function resolve(Request $request): FlagContext
 *       {
 *           $identity = $this->currentUser->get();
 *           if (!$identity->isAuthenticated()) return new FlagContext();
 *
 *           return new FlagContext(
 *               userId: $identity->id(),
 *               attributes: [
 *                   'roles'  => $identity->roles(),
 *                   'plan'   => $identity->getAttribute('plan'),
 *                   'region' => $request->headers->get('CF-IPCountry'),
 *               ],
 *           );
 *       }
 *   }
 *
 * Then rebind in config/services.php:
 *   $services->alias(FlagContextResolverInterface::class, AppFlagContextResolver::class);
 */
final class DefaultFlagContextResolver implements FlagContextResolverInterface
{
    public function __construct(private readonly CurrentUserProvider $currentUser) {}

    public function resolve(Request $request): FlagContext
    {
        $identity = $this->currentUser->get();

        if (!$identity->isAuthenticated()) {
            return new FlagContext();
        }

        return new FlagContext(
            userId: $identity->id(),
            attributes: ['roles' => $identity->roles()],
        );
    }
}
