<?php

declare(strict_types=1);

namespace Vortos\Analytics\Tests\Unit\Runtime;

use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Response;
use Vortos\Analytics\AnalyticsInterface;
use Vortos\Analytics\Capability\AnalyticsCapability;
use Vortos\Analytics\Event\AnalyticsEvent;
use Vortos\Analytics\Event\GroupAssociation;
use Vortos\Analytics\Event\IdentitySet;
use Vortos\Analytics\Runtime\HttpTerminateFlush;
use Vortos\Http\Contract\TerminableMiddlewareInterface;
use Vortos\Http\Request;
use Vortos\OpsKit\Driver\Capability\CapabilityDescriptor;

/**
 * The per-request flush. Analytics shipped nothing for weeks because this job was wired to
 * `KernelEvents::TERMINATE`, which `Vortos\Http\Kernel::terminate()` never dispatches — it
 * iterates terminable middleware and nothing else. Delivery quietly fell back to
 * BatchingAnalytics' own flush at 100 buffered events, so events arrived in round hundreds
 * or, in a quiet period, never.
 */
final class HttpTerminateFlushTest extends TestCase
{
    public function test_is_a_terminable_middleware_which_is_what_the_kernel_calls(): void
    {
        // The whole point of this class. An event subscriber here is dead code.
        self::assertInstanceOf(
            TerminableMiddlewareInterface::class,
            new HttpTerminateFlush($this->analytics()),
        );
    }

    public function test_flushes_when_the_request_terminates(): void
    {
        $analytics = $this->analytics();

        (new HttpTerminateFlush($analytics))->terminate(Request::create('/api/x', 'POST'), new Response());

        self::assertSame(1, $analytics->flushes, 'a request that captured events must ship them');
    }

    public function test_a_failing_flush_never_escapes(): void
    {
        // Runs after the response has been sent: there is nobody left to tell, and telemetry
        // must never become a second failure on a request that already succeeded.
        $analytics = $this->analytics(throwOnFlush: true);

        (new HttpTerminateFlush($analytics))->terminate(Request::create('/api/x', 'POST'), new Response());

        $this->addToAssertionCount(1);
    }

    private function analytics(bool $throwOnFlush = false): AnalyticsInterface
    {
        return new class($throwOnFlush) implements AnalyticsInterface {
            public int $flushes = 0;

            public function __construct(private readonly bool $throwOnFlush) {}

            public function name(): string { return 'spy'; }
            public function capture(AnalyticsEvent $event): void {}
            public function identify(IdentitySet $identity): void {}
            public function group(GroupAssociation $group): void {}

            public function flush(): void
            {
                $this->flushes++;

                if ($this->throwOnFlush) {
                    throw new \RuntimeException('backend unreachable');
                }
            }

            public function capabilities(): CapabilityDescriptor
            {
                return CapabilityDescriptor::create([AnalyticsCapability::Batching->value => false]);
            }
        };
    }
}
