<?php

declare(strict_types=1);

namespace Vortos\Tests\Security;

use PHPUnit\Framework\TestCase;
use Vortos\Http\Attribute\AsMiddleware;
use Vortos\Http\MiddlewareOrder;
use Vortos\Http\Request;
use Vortos\Http\Response;
use Vortos\Security\Csrf\Middleware\CsrfMiddleware;

final class CsrfMiddlewareTest extends TestCase
{
    public function test_csrf_middleware_runs_at_csrf_order(): void
    {
        $attrs = (new \ReflectionClass(CsrfMiddleware::class))->getAttributes(AsMiddleware::class);
        $this->assertNotEmpty($attrs);
        $this->assertSame(MiddlewareOrder::CSRF, $attrs[0]->newInstance()->order);
    }
}
