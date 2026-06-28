<?php

declare(strict_types=1);

namespace Vortos\FeatureFlags\Http;

use Vortos\Http\Request;
use Vortos\Auth\Identity\CurrentUserProvider;
use Vortos\FeatureFlags\FlagContext;

/**
 * Resolves flag evaluation context from the server-side authenticated session.
 *
 * ## Scope: server-side flag evaluation only
 *
 * This resolver is called exclusively by {@see FlagsController} (the server SDK delivery
 * endpoint). It is NOT called by:
 *   - {@see FlagBootstrapController} — CDN-cacheable snapshot, always context-free
 *   - {@see FlagStreamController}    — SSE push stream, always context-free
 *
 * Rebinding this resolver has no effect on bootstrap or SSE responses. If a browser SPA
 * needs per-user flag payloads at page load, it must call the server SDK endpoint
 * (FlagsController) directly — not the bootstrap snapshot.
 *
 * ## Adding custom attributes
 *
 * Implement {@see FlagContextResolverInterface} and rebind the alias:
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
 *
 * ## SDK key authentication
 *
 * {@see SdkKeyAuthMiddleware} runs before this resolver. It authenticates the SDK client
 * and sets the active project + environment scope from the key — it does NOT set user
 * identity. User identity is still resolved here from the authenticated session. Both
 * concerns are independent.
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
