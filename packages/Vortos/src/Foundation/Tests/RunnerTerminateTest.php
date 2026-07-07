<?php

declare(strict_types=1);

namespace Vortos\Foundation\Tests;

use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\Container;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;
use Vortos\Foundation\Reset\ServicesResetter;
use Vortos\Foundation\Runner;
use Vortos\Http\Request;

/**
 * Regression: in FrankenPHP worker mode nothing else calls Kernel::terminate(), so cleanUp() is the
 * sole HTTP flush trigger for terminable middleware (OTLP metrics, StatsD, log flush). It must fire
 * terminate() for the served request and do so BEFORE ServicesResetter::reset() discards per-request
 * state.
 */
final class RunnerTerminateTest extends TestCase
{
    public function test_cleanup_terminates_kernel_before_resetting_services(): void
    {
        $log = new class {
            /** @var list<string> */
            public array $events = [];
        };

        $kernel = new class($log) {
            public function __construct(private object $log) {}
            public function terminate(Request $request, SymfonyResponse $response): void
            {
                $this->log->events[] = 'terminate';
            }
        };

        $resetter = new class($log) {
            public function __construct(private object $log) {}
            public function reset(): void
            {
                $this->log->events[] = 'reset';
            }
        };

        $runner = $this->runnerWith('http', $kernel, $resetter);
        $runner->cleanUp();

        $this->assertSame(['terminate', 'reset'], $log->events);
    }

    public function test_cleanup_does_not_terminate_for_non_http_context(): void
    {
        $log = new class {
            /** @var list<string> */
            public array $events = [];
        };

        $kernel = new class($log) {
            public function __construct(private object $log) {}
            public function terminate(Request $request, SymfonyResponse $response): void
            {
                $this->log->events[] = 'terminate';
            }
        };

        $resetter = new class($log) {
            public function __construct(private object $log) {}
            public function reset(): void
            {
                $this->log->events[] = 'reset';
            }
        };

        $runner = $this->runnerWith('cli', $kernel, $resetter);
        $runner->cleanUp();

        $this->assertSame(['reset'], $log->events, 'terminable middleware is an HTTP concern only');
    }

    private function runnerWith(string $context, object $kernel, object $resetter): Runner
    {
        $container = new Container();
        $container->set('vortos', $kernel);
        $container->set(ServicesResetter::class, $resetter);

        $runner = new Runner('test', true, '/tmp/test-project', $context);

        $this->setPrivate($runner, 'container', $container);
        $this->setPrivate($runner, 'request', new Request());
        $this->setPrivate($runner, 'response', new SymfonyResponse());

        return $runner;
    }

    private function setPrivate(Runner $runner, string $property, mixed $value): void
    {
        $ref = new \ReflectionProperty($runner, $property);
        $ref->setValue($runner, $value);
    }
}
