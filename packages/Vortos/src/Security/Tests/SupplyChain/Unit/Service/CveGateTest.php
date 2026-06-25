<?php

declare(strict_types=1);

namespace Vortos\Security\Tests\SupplyChain\Unit\Service;

use PHPUnit\Framework\TestCase;
use Vortos\Security\SupplyChain\Model\ArtifactDigest;
use Vortos\Security\SupplyChain\Model\Vulnerability\KevCatalog;
use Vortos\Security\SupplyChain\Model\Vulnerability\Severity;
use Vortos\Security\SupplyChain\Model\Vulnerability\Vulnerability;
use Vortos\Security\SupplyChain\Model\Vulnerability\VulnerabilityReport;
use Vortos\Security\SupplyChain\Service\CveGate;
use Vortos\Security\SupplyChain\Service\CveGatePolicy;
use Vortos\Security\SupplyChain\Service\CveIgnoreEntry;

final class CveGateTest extends TestCase
{
    private const DIGEST = 'sha256:a1b2c3d4e5f6a1b2c3d4e5f6a1b2c3d4e5f6a1b2c3d4e5f6a1b2c3d4e5f6a1b2';

    private CveGate $gate;
    private \DateTimeImmutable $now;

    protected function setUp(): void
    {
        $this->gate = new CveGate();
        $this->now = new \DateTimeImmutable('2024-06-01T00:00:00Z');
    }

    public function test_clean_report_passes(): void
    {
        $report = $this->report([]);
        $decision = $this->gate->evaluate($report, null, new CveGatePolicy(), $this->now);
        self::assertTrue($decision->pass);
    }

    public function test_fixable_critical_fails(): void
    {
        $report = $this->report([
            new Vulnerability('CVE-2024-0001', 'pkg', '1.0', '1.1', Severity::Critical, false),
        ]);

        $decision = $this->gate->evaluate($report, null, new CveGatePolicy(), $this->now);
        self::assertFalse($decision->pass);
        self::assertSame(['CVE-2024-0001'], $decision->offendingCves);
    }

    public function test_unfixable_critical_fails_by_default(): void
    {
        $report = $this->report([
            new Vulnerability('CVE-2024-0001', 'pkg', '1.0', null, Severity::Critical, false),
        ]);

        $decision = $this->gate->evaluate($report, null, new CveGatePolicy(), $this->now);
        self::assertFalse($decision->pass);
    }

    public function test_unfixable_critical_passes_when_require_fix_available(): void
    {
        $report = $this->report([
            new Vulnerability('CVE-2024-0001', 'pkg', '1.0', null, Severity::Critical, false),
        ]);

        $policy = new CveGatePolicy(requireFixAvailable: true);
        $decision = $this->gate->evaluate($report, null, $policy, $this->now);
        self::assertTrue($decision->pass);
    }

    public function test_kev_listed_medium_fails(): void
    {
        $kev = KevCatalog::fromList(['CVE-2024-0001'], 'sha256:abc', $this->now);
        $report = $this->report([
            new Vulnerability('CVE-2024-0001', 'pkg', '1.0', '1.1', Severity::Medium, true),
        ]);

        $decision = $this->gate->evaluate($report, $kev, new CveGatePolicy(), $this->now);
        self::assertFalse($decision->pass);
    }

    public function test_kev_disabled_medium_passes(): void
    {
        $kev = KevCatalog::fromList(['CVE-2024-0001'], 'sha256:abc', $this->now);
        $report = $this->report([
            new Vulnerability('CVE-2024-0001', 'pkg', '1.0', '1.1', Severity::Medium, true),
        ]);

        $policy = new CveGatePolicy(failOnKevAnySeverity: false);
        $decision = $this->gate->evaluate($report, $kev, $policy, $this->now);
        self::assertTrue($decision->pass);
    }

    public function test_ignored_cve_passes(): void
    {
        $ignore = new CveIgnoreEntry('CVE-2024-0001', 'false positive', new \DateTimeImmutable('2025-01-01'));
        $report = $this->report([
            new Vulnerability('CVE-2024-0001', 'pkg', '1.0', '1.1', Severity::Critical, false),
        ]);

        $policy = new CveGatePolicy(ignoreList: [$ignore]);
        $decision = $this->gate->evaluate($report, null, $policy, $this->now);
        self::assertTrue($decision->pass);
    }

    public function test_expired_ignore_fails(): void
    {
        $ignore = new CveIgnoreEntry('CVE-2024-0001', 'temp ignore', new \DateTimeImmutable('2024-01-01'));
        $report = $this->report([
            new Vulnerability('CVE-2024-0001', 'pkg', '1.0', '1.1', Severity::Critical, false),
        ]);

        $policy = new CveGatePolicy(ignoreList: [$ignore]);
        $decision = $this->gate->evaluate($report, null, $policy, $this->now);
        self::assertFalse($decision->pass);
    }

    public function test_low_severity_passes(): void
    {
        $report = $this->report([
            new Vulnerability('CVE-2024-0001', 'pkg', '1.0', '1.1', Severity::Low, false),
        ]);

        $decision = $this->gate->evaluate($report, null, new CveGatePolicy(), $this->now);
        self::assertTrue($decision->pass);
    }

    public function test_fail_on_high_severity(): void
    {
        $report = $this->report([
            new Vulnerability('CVE-2024-0001', 'pkg', '1.0', '1.1', Severity::High, false),
        ]);

        $policy = new CveGatePolicy(failOn: Severity::High);
        $decision = $this->gate->evaluate($report, null, $policy, $this->now);
        self::assertFalse($decision->pass);
    }

    public function test_unknown_severity_treated_as_critical(): void
    {
        $vuln = Vulnerability::fromArray([
            'id' => 'CVE-2024-0001',
            'package_name' => 'pkg',
            'installed_version' => '1.0',
            'severity' => 'BOGUS',
        ]);

        $report = $this->report([$vuln]);
        $decision = $this->gate->evaluate($report, null, new CveGatePolicy(), $this->now);
        self::assertFalse($decision->pass);
    }

    /** @param list<Vulnerability> $vulns */
    private function report(array $vulns): VulnerabilityReport
    {
        return new VulnerabilityReport(
            new ArtifactDigest(self::DIGEST),
            'trivy',
            $this->now,
            $vulns,
        );
    }
}
