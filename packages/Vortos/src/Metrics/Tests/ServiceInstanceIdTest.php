<?php

declare(strict_types=1);

namespace Vortos\Metrics\Tests;

use PHPUnit\Framework\TestCase;
use Vortos\Metrics\OpenTelemetry\ServiceInstanceId;

/**
 * @see ServiceInstanceId
 */
final class ServiceInstanceIdTest extends TestCase
{
    protected function setUp(): void
    {
        ServiceInstanceId::reset();
        unset($_SERVER[ServiceInstanceId::ENV_VAR], $_ENV[ServiceInstanceId::ENV_VAR]);
    }

    protected function tearDown(): void
    {
        ServiceInstanceId::reset();
        unset($_SERVER[ServiceInstanceId::ENV_VAR], $_ENV[ServiceInstanceId::ENV_VAR]);
    }

    /**
     * THE property that makes the fix work. If the id changed between flushes, that thread's cumulative
     * counter would look like it restarted from zero on every request — reintroducing the false-reset
     * inflation this class removes, in a shape that is much harder to recognise.
     */
    public function test_the_id_is_stable_for_the_life_of_the_process(): void
    {
        $first = ServiceInstanceId::resolve();

        for ($i = 0; $i < 5; ++$i) {
            self::assertSame($first, ServiceInstanceId::resolve(), 'the id must not change between calls');
        }
    }

    /**
     * THE property the whole fix rests on: two worker threads of the same service must not share an id.
     * They each hold their own cumulative counter, so a shared identity makes the backend read one
     * thread's lower value as a counter reset and re-add the total.
     *
     * Asserted through the worker-mode branch explicitly rather than via resolve(), because the suite
     * runs under CLI — where resolve() correctly takes the PID branch and would return equal values.
     */
    public function test_worker_threads_get_distinct_ids(): void
    {
        $ids = [];
        for ($i = 0; $i < 20; ++$i) {
            $ids[] = ServiceInstanceId::generateFor(workerMode: true);
        }

        self::assertCount(20, array_unique($ids), 'every worker thread must get its own instance id');
    }

    /**
     * The mirror image, and the cardinality guard: outside worker mode statics do not survive a
     * request, so the id must be reproducible from the process itself. Randomness here would mint a
     * fresh series on every php-fpm request — an unbounded leak, worse than the bug being fixed.
     */
    public function test_non_worker_runtimes_get_a_reproducible_id(): void
    {
        $ids = [];
        for ($i = 0; $i < 5; ++$i) {
            $ids[] = ServiceInstanceId::generateFor(workerMode: false);
        }

        self::assertCount(1, array_unique($ids), 'a non-worker process must keep one stable id');
        self::assertStringEndsWith('-' . getmypid(), $ids[0]);
    }

    /**
     * Outside FrankenPHP worker mode PHP resets statics between requests, so the discriminator must be
     * something the process already owns — the PID — rather than fresh randomness. Randomness there
     * would mint a new instance id on every request, i.e. a new time series per request: an unbounded
     * cardinality leak, and a worse failure than the counter collision being fixed.
     *
     * The test suite runs under CLI, which is not worker mode, so this asserts the non-worker branch
     * as it will actually behave under php-fpm.
     */
    public function test_outside_worker_mode_the_id_is_derived_from_the_pid_not_randomness(): void
    {
        if (function_exists('frankenphp_handle_request')) {
            self::markTestSkipped('running under FrankenPHP worker mode; the PID branch is unreachable here');
        }

        self::assertStringEndsWith('-' . getmypid(), ServiceInstanceId::resolve());
    }

    /**
     * The consequence of the above, stated directly: two resolutions within one non-worker process must
     * agree even across a reset, because the PID has not changed. This is what makes the id stable
     * across php-fpm requests instead of leaking a series per request.
     */
    public function test_outside_worker_mode_the_id_is_reproducible_within_the_process(): void
    {
        if (function_exists('frankenphp_handle_request')) {
            self::markTestSkipped('running under FrankenPHP worker mode; the PID branch is unreachable here');
        }

        $first = ServiceInstanceId::resolve();
        ServiceInstanceId::reset();

        self::assertSame($first, ServiceInstanceId::resolve());
    }

    public function test_generated_id_is_prefixed_with_the_host_name(): void
    {
        $host = gethostname();
        self::assertIsString($host);

        self::assertStringStartsWith($host . '-', ServiceInstanceId::resolve());
    }

    /**
     * The host name alone is what every thread inside a container already reports, so it cannot be the
     * whole id — there must be something after it that differs.
     */
    public function test_generated_id_is_not_just_the_host_name(): void
    {
        $host = gethostname();
        self::assertIsString($host);

        self::assertNotSame($host, ServiceInstanceId::resolve());
    }

    public function test_explicit_configuration_wins(): void
    {
        self::assertSame('replica-7', ServiceInstanceId::resolve('replica-7'));
    }

    public function test_environment_variable_is_honoured(): void
    {
        $_SERVER[ServiceInstanceId::ENV_VAR] = 'from-env';

        self::assertSame('from-env', ServiceInstanceId::resolve());
    }

    public function test_explicit_configuration_beats_the_environment(): void
    {
        $_SERVER[ServiceInstanceId::ENV_VAR] = 'from-env';

        self::assertSame('explicit', ServiceInstanceId::resolve('explicit'));
    }

    /** An empty configured value means "not configured", not "use an empty id". */
    public function test_empty_configuration_falls_through_to_a_generated_id(): void
    {
        $id = ServiceInstanceId::resolve('');

        self::assertNotSame('', $id);
        self::assertStringContainsString('-', $id);
    }
}
