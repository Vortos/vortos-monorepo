<?php

declare(strict_types=1);

namespace Vortos\Auth\Tests\RateLimit;

use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Vortos\Auth\Contract\UserIdentityInterface;
use Vortos\Auth\RateLimit\Attribute\RateLimit;
use Vortos\Auth\RateLimit\Compiler\RateLimitCompilerPass;
use Vortos\Auth\RateLimit\Contract\RateLimitPolicyInterface;
use Vortos\Auth\RateLimit\Middleware\IpGlobalRateLimitMiddleware;
use Vortos\Auth\RateLimit\RateLimitRule;
use Vortos\Auth\RateLimit\RateLimitScope;
use Vortos\Auth\RateLimit\Middleware\UserRateLimitMiddleware;
use Vortos\Auth\RateLimit\RateLimitService;
use Vortos\Http\Contract\MiddlewareInterface;
use Vortos\Http\DependencyInjection\Compiler\RegisterMiddlewarePass;
use Vortos\Http\Pipeline\Pipeline;

/**
 * Guards the wiring that made rate limiting silently inert in production.
 *
 * The HTTP kernel runs a Vortos\Http\Pipeline and never dispatches Symfony kernel
 * events, so a rate limiter registered as a `kernel.event_subscriber` compiles
 * cleanly, appears in the container, reports a fully populated route map — and is
 * never called. Nothing failed; requests were simply never counted, and the only
 * 429s users saw came from the edge proxy, which masked it.
 *
 * These tests assert the property that actually matters: the enforcement classes
 * reach the pipeline that runs them.
 */
final class RateLimitPipelineWiringTest extends TestCase
{
    /**
     * The contract RegisterMiddlewarePass collects on. An enforcement class that does
     * not implement it cannot run, whatever else the container says about it.
     */
    public function test_rate_limit_middlewares_implement_the_pipeline_contract(): void
    {
        self::assertTrue(
            is_a(IpGlobalRateLimitMiddleware::class, MiddlewareInterface::class, true),
            'IP/Global rate limiting must be pipeline middleware; kernel events are never dispatched.',
        );
        self::assertTrue(
            is_a(UserRateLimitMiddleware::class, MiddlewareInterface::class, true),
            'User rate limiting must be pipeline middleware; kernel events are never dispatched.',
        );
    }

    /**
     * The end-to-end property: after compilation, both middlewares are arguments of
     * the Pipeline. This is what was false in production.
     */
    public function test_compiled_pipeline_contains_both_rate_limit_middlewares(): void
    {
        $container = new ContainerBuilder();
        $container->register(Pipeline::class, Pipeline::class)->setArguments([[]]);
        $container->register(IpGlobalRateLimitMiddleware::class, IpGlobalRateLimitMiddleware::class);
        $container->register(UserRateLimitMiddleware::class, UserRateLimitMiddleware::class);

        (new RegisterMiddlewarePass())->process($container);

        $wired = array_map(
            static fn ($ref): string => (string) $ref,
            $container->getDefinition(Pipeline::class)->getArgument(0),
        );

        self::assertContains(IpGlobalRateLimitMiddleware::class, $wired);
        self::assertContains(UserRateLimitMiddleware::class, $wired);
    }

    /**
     * IP and Global scopes must be enforced before authentication — an unauthenticated
     * flood is exactly what they exist to stop — while the User scope needs a resolved
     * identity. Collapsing them into one middleware would force one of the two to sit
     * at the wrong point in the chain.
     */
    public function test_ip_scope_is_enforced_before_user_scope(): void
    {
        $container = new ContainerBuilder();
        $container->register(Pipeline::class, Pipeline::class)->setArguments([[]]);
        $container->register(UserRateLimitMiddleware::class, UserRateLimitMiddleware::class);
        $container->register(IpGlobalRateLimitMiddleware::class, IpGlobalRateLimitMiddleware::class);

        (new RegisterMiddlewarePass())->process($container);

        $wired = array_map(
            static fn ($ref): string => (string) $ref,
            $container->getDefinition(Pipeline::class)->getArgument(0),
        );

        self::assertLessThan(
            array_search(UserRateLimitMiddleware::class, $wired, true),
            array_search(IpGlobalRateLimitMiddleware::class, $wired, true),
            'IP/Global limiting must run outside (before) User limiting.',
        );
    }

    /**
     * The compiler pass must fill the route map on the service the middlewares delegate
     * to. Pointed at anything else — as it was at the deleted event subscriber — both
     * middlewares run against an empty map and allow every request, which no test of
     * the service in isolation would notice.
     */
    public function test_compiler_pass_fills_the_route_map_on_the_service(): void
    {
        $container = new ContainerBuilder();

        $container->register(RateLimitService::class, RateLimitService::class)
            ->setArguments([null, null, [], []]);
        $container->register(FixtureRateLimitPolicy::class, FixtureRateLimitPolicy::class);
        $container->register(FixtureRateLimitedController::class, FixtureRateLimitedController::class)
            ->addTag('vortos.api.controller');

        (new RateLimitCompilerPass())->process($container);

        $definition = $container->getDefinition(RateLimitService::class);
        $routeMap   = $definition->getArgument('$routeMap');
        $policies   = $definition->getArgument('$policies');

        self::assertArrayHasKey(
            FixtureRateLimitedController::class,
            $routeMap,
            'A controller carrying #[RateLimit] must reach the service that enforces it.',
        );
        self::assertSame(
            FixtureRateLimitPolicy::class,
            $routeMap[FixtureRateLimitedController::class][0]['policy'],
        );
        self::assertSame(
            RateLimitScope::Ip,
            $routeMap[FixtureRateLimitedController::class][0]['per'],
        );
        self::assertArrayHasKey(FixtureRateLimitPolicy::class, $policies);
    }
}

#[RateLimit(FixtureRateLimitPolicy::class, per: RateLimitScope::Ip)]
final class FixtureRateLimitedController
{
    public function __invoke(): void {}
}

final class FixtureRateLimitPolicy implements RateLimitPolicyInterface
{
    public function getLimit(UserIdentityInterface $identity): RateLimitRule
    {
        return new RateLimitRule(limit: 5, windowSeconds: 60);
    }
}
