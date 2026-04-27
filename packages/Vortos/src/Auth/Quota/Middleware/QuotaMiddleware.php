<?php
declare(strict_types=1);

namespace Vortos\Auth\Quota\Middleware;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Vortos\Auth\Identity\CurrentUserProvider;
use Vortos\Auth\Quota\Contract\QuotaPolicyInterface;
use Vortos\Auth\Quota\Storage\RedisQuotaStore;

/**
 * Enforces #[RequiresQuota] on controllers.
 * Priority 3 — after feature access (4).
 * Zero reflection at runtime — reads compile-time map.
 */
final class QuotaMiddleware implements EventSubscriberInterface
{
    /**
     * @param array<string, list<array{quota: string, cost: int}>> $routeMap
     * @param array<string, QuotaPolicyInterface> $policies
     */
    public function __construct(
        private CurrentUserProvider $currentUser,
        private RedisQuotaStore $store,
        private array $routeMap,
        private array $policies,
    ) {}

    public static function getSubscribedEvents(): array
    {
        return [KernelEvents::REQUEST => ['onKernelRequest', 3]];
    }

    public function onKernelRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) return;

        $controller = $this->extractControllerClass(
            $event->getRequest()->attributes->get('_controller')
        );

        if ($controller === null || !isset($this->routeMap[$controller])) return;

        $identity = $this->currentUser->get();

        if (!$identity->isAuthenticated()) return;

        foreach ($this->routeMap[$controller] as $rule) {
            foreach ($this->policies as $policy) {
                $quotaRule = $policy->getQuota($identity, $rule['quota']);

                if ($quotaRule->isUnlimited()) continue;

                $current = $this->store->get($identity->id(), $rule['quota'], $quotaRule->period);

                if ($current + $rule['cost'] > $quotaRule->limit) {
                    $event->setResponse(new JsonResponse(
                        [
                            'error'   => 'Quota Exceeded',
                            'message' => 'You have exceeded your usage quota for this period.',
                            'quota'   => $rule['quota'],
                            'limit'   => $quotaRule->limit,
                            'current' => $current,
                            'period'  => $quotaRule->period->value,
                        ],
                        Response::HTTP_TOO_MANY_REQUESTS,
                    ));
                    return;
                }

                // Increment after all checks pass
                $this->store->increment($identity->id(), $rule['quota'], $quotaRule->period, $rule['cost']);
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
