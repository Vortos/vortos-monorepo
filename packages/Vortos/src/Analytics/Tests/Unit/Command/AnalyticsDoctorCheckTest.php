<?php

declare(strict_types=1);

namespace Vortos\Analytics\Tests\Unit\Command;

use PHPUnit\Framework\TestCase;
use Vortos\Analytics\Command\AnalyticsDoctorCheck;
use Vortos\Analytics\Driver\Null\NullAnalytics;
use Vortos\Analytics\Registry\AnalyticsDriverRegistry;
use Vortos\Deploy\Preflight\PreflightCategory;
use Vortos\Deploy\Preflight\PreflightStatus;
use Vortos\Deploy\Tests\Fixtures\InMemoryServiceLocator;
use Vortos\Deploy\Tests\Fixtures\PreflightTestFactory;

final class AnalyticsDoctorCheckTest extends TestCase
{
    use PreflightTestFactory;

    public function test_registered_driver_passes(): void
    {
        $check = new AnalyticsDoctorCheck($this->registry(), 'null');
        $finding = $check->check($this->context());

        $this->assertSame(PreflightStatus::Pass, $finding->status);
        $this->assertSame(PreflightCategory::Capability, $finding->category);
    }

    public function test_unregistered_driver_fails_naming_known_drivers(): void
    {
        $check = new AnalyticsDoctorCheck($this->registry(), 'posthog');
        $finding = $check->check($this->context());

        $this->assertSame(PreflightStatus::Fail, $finding->status);
        $this->assertStringContainsString('null', $finding->detail);
    }

    public function test_empty_driver_key_fails(): void
    {
        $check = new AnalyticsDoctorCheck($this->registry(), '');
        $finding = $check->check($this->context());

        $this->assertSame(PreflightStatus::Fail, $finding->status);
    }

    public function test_finding_never_contains_secret_looking_material(): void
    {
        $check = new AnalyticsDoctorCheck($this->registry(), 'null');
        $finding = $check->check($this->context());

        foreach (['detail' => $finding->detail, 'summary' => $finding->summary, 'remediation' => $finding->remediation] as $field) {
            $this->assertStringNotContainsStringIgnoringCase('api_key', $field);
            $this->assertStringNotContainsStringIgnoringCase('dsn', $field);
        }
    }

    private function registry(): AnalyticsDriverRegistry
    {
        return new AnalyticsDriverRegistry(new InMemoryServiceLocator(['null' => new NullAnalytics()]));
    }
}
