<?php

declare(strict_types=1);

namespace Vortos\Security\Csrf\Middleware;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Vortos\Security\Csrf\CsrfTokenService;
use Vortos\Security\Event\CsrfViolationEvent;
use Vortos\Security\Event\SecurityEventDispatcher;

/**
 * CSRF protection using the double-submit cookie pattern.
 *
 * Priority 20 on REQUEST — after routing (32), before auth (6).
 * Priority 85 on RESPONSE — issues the initial CSRF cookie on the first response.
 *
 * Routes in $skipControllers (built by CsrfCompilerPass from #[SkipCsrf]) bypass
 * validation — use for stateless JWT endpoints and webhook receivers.
 *
 * Safe methods (GET/HEAD/OPTIONS) are always allowed — CSRF only applies to
 * state-changing requests.
 */
final class CsrfMiddleware implements EventSubscriberInterface
{
    /**
     * @param list<string> $skipControllers Controller FQCNs or 'Class::method' strings
     *                                       pre-built by CsrfCompilerPass at compile time.
     */
    public function __construct(
        private readonly CsrfTokenService      $csrf,
        private readonly SecurityEventDispatcher $events,
        private readonly bool                  $enabled,
        private readonly array                 $skipControllers,
    ) {}

    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::REQUEST  => ['onKernelRequest', 20],
            KernelEvents::RESPONSE => ['onKernelResponse', 85],
        ];
    }

    public function onKernelRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest() || !$this->enabled) {
            return;
        }

        $request = $event->getRequest();

        if (in_array($request->getMethod(), ['GET', 'HEAD', 'OPTIONS', 'TRACE'], true)) {
            return;
        }

        $controller = $this->resolveControllerKey($request->attributes->get('_controller'));
        if ($controller !== null && $this->isSkipped($controller)) {
            return;
        }

        if (!$this->csrf->validate($request)) {
            $this->events->dispatch(new CsrfViolationEvent(
                $request->getClientIp() ?? 'unknown',
                $request->getPathInfo(),
                $request->getMethod(),
            ));

            $event->setResponse(new JsonResponse(
                [
                    'error'   => 'CSRF token invalid or missing.',
                    'message' => 'Include the token from the ' . $this->csrf->cookieName() . ' cookie '
                        . 'in the ' . $this->csrf->headerName() . ' request header.',
                ],
                Response::HTTP_FORBIDDEN,
            ));
        }
    }

    public function onKernelResponse(ResponseEvent $event): void
    {
        if (!$event->isMainRequest() || !$this->enabled) {
            return;
        }

        // Issue a CSRF cookie on the first request where none is present
        if (!$this->csrf->hasCookie($event->getRequest())) {
            $this->csrf->issue($event->getResponse());
        }
    }

    private function isSkipped(string $controllerKey): bool
    {
        foreach ($this->skipControllers as $skip) {
            if ($skip === $controllerKey || str_starts_with($controllerKey, $skip . '::')) {
                return true;
            }
        }
        return false;
    }

    private function resolveControllerKey(mixed $controller): ?string
    {
        if (is_string($controller)) {
            return $controller;
        }
        if (is_array($controller)) {
            $class = is_object($controller[0]) ? get_class($controller[0]) : $controller[0];
            return isset($controller[1]) ? $class . '::' . $controller[1] : $class;
        }
        if (is_object($controller)) {
            return get_class($controller);
        }
        return null;
    }
}
