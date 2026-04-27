<?php
declare(strict_types=1);

namespace Vortos\Auth\RateLimit\Middleware;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Vortos\Auth\Identity\CurrentUserProvider;
use Vortos\Auth\RateLimit\Attribute\RateLimit;
use Vortos\Auth\RateLimit\Contract\RateLimitPolicyInterface;
use Vortos\Auth\RateLimit\RateLimitScope;
use Vortos\Auth\RateLimit\Storage\RedisRateLimitStore;

/**
 * Enforces #[RateLimit] on controllers.
 *
 * Priority 7 — runs before AuthMiddleware (6) so IP-based limits
 * apply even to unauthenticated requests.
 *
 * Runtime: reads pre-built compile-time map of controller → rate limit rules.
 * Zero reflection at runtime.
 *
 * Returns 429 with Retry-After header when limit exceeded.
 */
final class RateLimitMiddleware implements EventSubscriberInterface
{
    /**
     * @param array<string, list<array{policy: string, per: RateLimitScope}>> $routeMap
     *        Pre-built by RateLimitCompilerPass at compile time.
     * @param array<string, RateLimitPolicyInterface> $policies
     *        Pre-built policy map by RateLimitCompilerPass.
     */
    public function __construct(
        private CurrentUserProvider $currentUser,
        private RedisRateLimitStore $store,
        private array $routeMap,
        private array $policies,
    ) {}

    public static function getSubscribedEvents(): array
    {
        return [KernelEvents::REQUEST => ['onKernelRequest', 7]];
    }

    public function onKernelRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) return;

        $request = $event->getRequest();
        $controller = $this->extractControllerClass($request->attributes->get('_controller'));

        if ($controller === null || !isset($this->routeMap[$controller])) return;

        $identity = $this->currentUser->get();

        foreach ($this->routeMap[$controller] as $rule) {
            $policyClass = $rule['policy'];
            $scope = $rule['per'];

            if (!isset($this->policies[$policyClass])) continue;

            $policy = $this->policies[$policyClass];
            $limit = $policy->getLimit($identity);

            if ($limit->isUnlimited()) continue;

            $key = match($scope) {
                RateLimitScope::User   => "rl:user:{$identity->id()}:{$controller}:{$policyClass}",
                RateLimitScope::Ip     => "rl:ip:{$request->getClientIp()}:{$controller}:{$policyClass}",
                RateLimitScope::Global => "rl:global:{$controller}:{$policyClass}",
            };

            $current = $this->store->increment($key, $limit->windowSeconds);

            if ($current > $limit->limit) {
                $retryAfter = $this->store->getTtl($key);
                $event->setResponse(new JsonResponse(
                    [
                        'error'   => 'Too Many Requests',
                        'message' => 'Rate limit exceeded. Please slow down.',
                        'limit'   => $limit->limit,
                        'window'  => $limit->windowSeconds,
                    ],
                    Response::HTTP_TOO_MANY_REQUESTS,
                    ['Retry-After' => $retryAfter],
                ));
                return;
            }
        }
    }

    private function extractControllerClass(mixed $controller): ?string
    {
        if (is_string($controller)) return explode('::', $controller)[0];
        if (is_array($controller)) return is_object($controller[0]) ? get_class($controller[0]) : $controller[0];
        if (is_object($controller)) return get_class($controller);
        return null;
    }
}
