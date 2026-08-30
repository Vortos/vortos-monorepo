<?php

declare(strict_types=1);

namespace Vortos\ObjectStore\Tests\Preflight;

use PHPUnit\Framework\TestCase;
use ReflectionClass;
use RuntimeException;
use Vortos\Deploy\Preflight\PreflightContext;
use Vortos\Deploy\Preflight\PreflightStatus;
use Vortos\Foundation\Health\Contract\HealthCheckInterface;
use Vortos\Foundation\Health\HealthResult;
use Vortos\ObjectStore\Preflight\ObjectStoreReachableDoctorCheck;

final class ObjectStoreReachableDoctorCheckTest extends TestCase
{
    public function test_reachable_bucket_passes(): void
    {
        $finding = $this->check($this->store(healthy: true))->check($this->context());

        self::assertSame(PreflightStatus::Pass, $finding->status);
        self::assertStringContainsString('sqoura-prod', $finding->summary);
    }

    public function test_unreachable_bucket_fails_the_deploy(): void
    {
        $finding = $this->check($this->store(healthy: false, error: 'AccessDenied'))->check($this->context());

        self::assertSame(PreflightStatus::Fail, $finding->status);
        self::assertStringContainsString('AccessDenied', $finding->detail);
        self::assertNotSame('', $finding->remediation);
    }

    public function test_a_throwing_check_fails_closed_rather_than_escaping(): void
    {
        $store = new class implements HealthCheckInterface {
            public function name(): string { return 'object_store'; }
            public function check(): HealthResult { throw new RuntimeException('DNS failure'); }
        };

        $finding = $this->check($store)->check($this->context());

        self::assertSame(PreflightStatus::Fail, $finding->status);
        self::assertStringContainsString('DNS failure', $finding->detail);
    }

    public function test_missing_bucket_name_fails_without_calling_the_store(): void
    {
        $store = new class implements HealthCheckInterface {
            public function name(): string { return 'object_store'; }
            public function check(): HealthResult
            {
                throw new RuntimeException('must not be reached - there is no bucket to reach');
            }
        };

        $finding = (new ObjectStoreReachableDoctorCheck($store, 's3', 'r2', ''))->check($this->context());

        self::assertSame(PreflightStatus::Fail, $finding->status);
        self::assertStringContainsString('not configured', $finding->summary);
    }

    public function test_log_driver_skips_because_there_is_no_bucket_to_be_wrong_about(): void
    {
        $finding = (new ObjectStoreReachableDoctorCheck(null, 'log', 'r2', ''))->check($this->context());

        self::assertSame(PreflightStatus::Skip, $finding->status);
    }

    private function check(HealthCheckInterface $store): ObjectStoreReachableDoctorCheck
    {
        return new ObjectStoreReachableDoctorCheck($store, 's3', 'r2', 'sqoura-prod');
    }

    private function store(bool $healthy, ?string $error = null): HealthCheckInterface
    {
        return new class($healthy, $error) implements HealthCheckInterface {
            public function __construct(private bool $healthy, private ?string $error) {}
            public function name(): string { return 'object_store'; }
            public function check(): HealthResult
            {
                return new HealthResult('object_store', $this->healthy, 1.0, $this->error, 'object_store_unreachable');
            }
        };
    }

    /** The check reads nothing off the context; it is part of the interface, not the behaviour. */
    private function context(): PreflightContext
    {
        return (new ReflectionClass(PreflightContext::class))->newInstanceWithoutConstructor();
    }
}
