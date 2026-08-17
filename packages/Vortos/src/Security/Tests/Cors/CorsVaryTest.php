<?php

declare(strict_types=1);

namespace Vortos\Security\Tests\Cors;

use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Response;
use Vortos\Security\Cors\Middleware\CorsMiddleware;
use Vortos\Http\Request;

/**
 * Vary is shared: CORS needs Origin on it, and so do the responses that vary on
 * something of their own. Overwriting it dropped the controller's choice on exactly
 * the cross-origin responses a browser SPA reads.
 */
final class CorsVaryTest extends TestCase
{
    public function testAddsOriginToAVaryTheResponseAlreadyCarries(): void
    {
        $response = $this->dispatch(static function (): Response {
            $response = new Response('', 200);
            $response->headers->set('Vary', 'Authorization');

            return $response;
        });

        self::assertSame('Authorization, Origin', $response->headers->get('Vary'));
    }

    public function testSetsVaryWhenTheResponseCarriesNone(): void
    {
        $response = $this->dispatch(static fn (): Response => new Response('', 200));

        self::assertSame('Origin', $response->headers->get('Vary'));
    }

    public function testDoesNotRepeatOriginRegardlessOfCase(): void
    {
        $response = $this->dispatch(static function (): Response {
            $response = new Response('', 200);
            $response->headers->set('Vary', 'origin');

            return $response;
        });

        self::assertSame('origin', $response->headers->get('Vary'));
    }

    public function testLeavesAWildcardVaryAlone(): void
    {
        $response = $this->dispatch(static function (): Response {
            $response = new Response('', 200);
            $response->headers->set('Vary', '*');

            return $response;
        });

        self::assertSame('*', $response->headers->get('Vary'));
    }

    private function dispatch(\Closure $next): Response
    {
        $middleware = new CorsMiddleware(
            [
                'origins'         => ['https://app.example.test'],
                'methods'         => ['GET', 'POST'],
                'credentials'     => true,
                'exposed_headers' => [],
                'max_age'         => 600,
            ],
            [],
        );

        $request = Request::create('/api/me/permissions', 'GET');
        $request->headers->set('Origin', 'https://app.example.test');

        return $middleware->handle($request, $next);
    }
}
