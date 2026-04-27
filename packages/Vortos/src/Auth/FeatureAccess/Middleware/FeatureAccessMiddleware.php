<?php
declare(strict_types=1);

namespace Vortos\Auth\FeatureAccess\Middleware;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Vortos\Auth\FeatureAccess\Contract\FeatureAccessPolicyInterface;
use Vortos\Auth\Identity\CurrentUserProvider;

/**
 * Enforces #[RequiresFeatureAccess] on controllers.
 * Priority 4 — after auth (6) and authorization (5).
 *
 * Returns 403 when denied.
 * Returns 402 (Payment Required) when paymentRequired: true.
 *
 * Runtime: zero reflection — reads compile-time map.
 */
final class FeatureAccessMiddleware implements EventSubscriberInterface
{
    /**
     * @param array<string, list<array{feature: string, paymentRequired: bool}>> $routeMap
     * @param array<string, FeatureAccessPolicyInterface> $policies
     */
    public function __construct(
        private CurrentUserProvider $currentUser,
        private array $routeMap,
        private array $policies,
    ) {}

    public static function getSubscribedEvents(): array
    {
        return [KernelEvents::REQUEST => ['onKernelRequest', 4]];
    }

    public function onKernelRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) return;

        $controller = $this->extractControllerClass(
            $event->getRequest()->attributes->get('_controller')
        );

        if ($controller === null || !isset($this->routeMap[$controller])) return;

        $identity = $this->currentUser->get();

        foreach ($this->routeMap[$controller] as $rule) {
            foreach ($this->policies as $policy) {
                if (!$policy->canAccess($identity, $rule['feature'])) {
                    $status = $rule['paymentRequired']
                        ? Response::HTTP_PAYMENT_REQUIRED
                        : Response::HTTP_FORBIDDEN;

                    $event->setResponse(new JsonResponse(
                        [
                            'error'   => $rule['paymentRequired'] ? 'Payment Required' : 'Forbidden',
                            'message' => $rule['paymentRequired']
                                ? 'This feature requires an active subscription.'
                                : 'Your plan does not include access to this feature.',
                            'feature' => $rule['feature'],
                        ],
                        $status,
                    ));
                    return;
                }
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
