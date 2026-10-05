<?php

declare(strict_types=1);

namespace Vortos\Security\Tests\Cors;

use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\HttpFoundation\Response;
use Vortos\Http\Request;
use Vortos\Security\Cors\Attribute\Cors;
use Vortos\Security\Cors\Middleware\CorsMiddleware;
use Vortos\Security\DependencyInjection\Compiler\CorsCompilerPass;

#[Cors(origins: ['https://marketing.example'], methods: ['POST', 'OPTIONS'], credentials: false)]
final class CorsCompilerPassTestPublicController
{
    public function __invoke(): Response
    {
        return new Response('ok');
    }
}

/**
 * The compiler pass is what makes #[Cors] mean anything — without it the middleware's override map
 * is the empty array it is constructed with and every per-route grant is silently ignored, which is
 * how a public endpoint ended up judged by the global credentialed allowlist in production.
 */
final class CorsCompilerPassTest extends TestCase
{
    public function test_it_builds_a_route_override_from_a_class_level_cors_attribute(): void
    {
        $container = new ContainerBuilder();

        $middleware = new Definition(CorsMiddleware::class);
        $middleware->setArguments([['origins' => ['https://app.example']], []]);
        $container->setDefinition(CorsMiddleware::class, $middleware);

        $controller = new Definition(CorsCompilerPassTestPublicController::class);
        $controller->addTag('vortos.api.controller');
        $container->setDefinition(CorsCompilerPassTestPublicController::class, $controller);

        (new CorsCompilerPass())->process($container);

        $routeMap = $container->getDefinition(CorsMiddleware::class)->getArgument('$routeMap');

        self::assertArrayHasKey(CorsCompilerPassTestPublicController::class, $routeMap);
        $override = $routeMap[CorsCompilerPassTestPublicController::class];
        self::assertSame(['https://marketing.example'], $override['origins']);
        self::assertFalse($override['credentials']);
        // Unset attribute properties must NOT appear, so they fall through to the global config.
        self::assertArrayNotHasKey('allowed_headers', $override);
    }

    public function test_the_override_actually_changes_the_response_for_that_route(): void
    {
        // End-to-end: a non-credentialed origin the GLOBAL config would reject is accepted on this
        // one route, and the response carries no Allow-Credentials — the exact shape a public form
        // endpoint needs when the site has no session.
        $routeMap = [
            CorsCompilerPassTestPublicController::class => [
                'origins'     => ['https://marketing.example'],
                'credentials' => false,
            ],
        ];
        $middleware = new CorsMiddleware(
            ['origins' => ['https://app.example'], 'methods' => ['GET', 'POST'], 'credentials' => true, 'exposed_headers' => [], 'max_age' => 600],
            $routeMap,
        );

        $request = Request::create('/api/leads', 'POST');
        $request->headers->set('Origin', 'https://marketing.example');
        $request->attributes->set('_cors_owner', CorsCompilerPassTestPublicController::class);

        $response = $middleware->handle($request, fn() => new Response('ok', 200));

        self::assertSame('https://marketing.example', $response->headers->get('Access-Control-Allow-Origin'));
        self::assertNull($response->headers->get('Access-Control-Allow-Credentials'));
    }
}
