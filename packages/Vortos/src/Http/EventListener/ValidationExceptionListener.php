<?php

declare(strict_types=1);

namespace Vortos\Http\EventListener;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Vortos\Cqrs\Validation\ValidationException;

/**
 * Converts ValidationException → 422 JSON response.
 *
 * Priority 64 — before ErrorListener (priority 0).
 * Only handles main requests.
 */
final class ValidationExceptionListener implements EventSubscriberInterface
{
    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::EXCEPTION => ['onKernelException', 64],
        ];
    }

    public function onKernelException(ExceptionEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $exception = $event->getThrowable();

        if (!$exception instanceof ValidationException) {
            return;
        }

        $event->setResponse(new JsonResponse($exception->toResponseArray(), 422));
    }
}
