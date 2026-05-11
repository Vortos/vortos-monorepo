<?php

declare(strict_types=1);

namespace Vortos\Tests\Security;

use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpKernel\KernelEvents;
use Vortos\Security\Csrf\Middleware\CsrfMiddleware;

final class CsrfMiddlewareTest extends TestCase
{
    public function test_request_listener_runs_after_routing_and_before_auth(): void
    {
        $events = CsrfMiddleware::getSubscribedEvents();

        $this->assertSame(['onKernelRequest', 20], $events[KernelEvents::REQUEST]);
    }
}
