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
use Vortos\Auth\Quota\QuotaRule;
use Vortos\Auth\Quota\Storage\RedisQuotaStore;

/**
 * Enforces #[RequiresQuota] on controllers.
 * Priority 0 — after feature access (1).
 * Zero reflection at runtime — reads compile-time map.
 *
 * For each rule, the most restrictive non-unlimited policy result wins.
 * Exactly one increment is performed per passing rule.
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
        return [KernelEvents::REQUEST => ['onKernelRequest', 0]];
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
            // Find the most restrictive non-unlimited quota across all policies
            $mostRestrictive = $this->resolveQuota($identity, $rule['quota']);

            if ($mostRestrictive === null) continue;

            $current = $this->store->get($identity->id(), $rule['quota'], $mostRestrictive->period);

            if ($current + $rule['cost'] > $mostRestrictive->limit) {
                $event->setResponse(new JsonResponse(
                    [
                        'error'   => 'Quota Exceeded',
                        'message' => 'You have exceeded your usage quota for this period.',
                        'quota'   => $rule['quota'],
                        'limit'   => $mostRestrictive->limit,
                        'current' => $current,
                        'period'  => $mostRestrictive->period->value,
                    ],
                    Response::HTTP_TOO_MANY_REQUESTS,
                ));
                return;
            }

            $this->store->increment($identity->id(), $rule['quota'], $mostRestrictive->period, $rule['cost']);
        }
    }

    /**
     * Returns the most restrictive (lowest limit) non-unlimited QuotaRule across all policies,
     * or null if every policy returns unlimited for this quota.
     */
    private function resolveQuota(mixed $identity, string $quota): ?QuotaRule
    {
        $result = null;

        foreach ($this->policies as $policy) {
            $rule = $policy->getQuota($identity, $quota);
            if ($rule->isUnlimited()) continue;
            if ($result === null || $rule->limit < $result->limit) {
                $result = $rule;
            }
        }

        return $result;
    }

    private function extractControllerClass(mixed $controller): ?string
    {
        if (is_string($controller)) return explode('::', $controller)[0];
        if (is_array($controller)) return is_object($controller[0]) ? get_class($controller[0]) : $controller[0];
        if (is_object($controller)) return get_class($controller);
        return null;
    }
}
