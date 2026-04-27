<?php
declare(strict_types=1);

namespace Vortos\Auth\TwoFactor\Middleware;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Vortos\Auth\Identity\CurrentUserProvider;
use Vortos\Auth\TwoFactor\Contract\TwoFactorVerifierInterface;

/**
 * Enforces #[Requires2FA] on controllers.
 * Priority 5.5 — between auth (6) and authorization (5).
 * Zero reflection — reads compile-time map.
 *
 * Returns 403 with challenge URL when 2FA not verified.
 */
final class TwoFactorMiddleware implements EventSubscriberInterface
{
    /**
     * @param list<string> $protectedControllers Pre-built by TwoFactorCompilerPass.
     */
    public function __construct(
        private CurrentUserProvider $currentUser,
        private ?TwoFactorVerifierInterface $verifier,
        private array $protectedControllers,
    ) {}

    public static function getSubscribedEvents(): array
    {
        // Between auth (6) and authorization (5)
        return [KernelEvents::REQUEST => ['onKernelRequest', 55, ]];
    }

    public function onKernelRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) return;
        if ($this->verifier === null) return;

        $request = $event->getRequest();
        $controller = $this->extractControllerClass($request->attributes->get('_controller'));

        if ($controller === null || !in_array($controller, $this->protectedControllers, true)) return;

        $identity = $this->currentUser->get();

        if (!$identity->isAuthenticated()) return; // Auth middleware handles 401

        if (!$this->verifier->isVerified($identity, $request)) {
            $event->setResponse(new JsonResponse(
                [
                    'error'         => 'Two-Factor Authentication Required',
                    'message'       => 'This action requires 2FA verification.',
                    'challenge_url' => $this->verifier->getChallengeUrl(),
                ],
                Response::HTTP_FORBIDDEN,
            ));
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
