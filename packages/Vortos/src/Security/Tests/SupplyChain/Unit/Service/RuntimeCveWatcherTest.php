<?php

declare(strict_types=1);

namespace Vortos\Security\Tests\SupplyChain\Unit\Service;

use PHPUnit\Framework\TestCase;
use Vortos\Security\SupplyChain\Model\ArtifactDigest;
use Vortos\Security\SupplyChain\Model\Vulnerability\Severity;
use Vortos\Security\SupplyChain\Model\Vulnerability\Vulnerability;
use Vortos\Security\SupplyChain\Model\Vulnerability\VulnerabilityReport;
use Vortos\Security\SupplyChain\Service\RuntimeCveWatcher;

final class RuntimeCveWatcherTest extends TestCase
{
    private const DIGEST = 'sha256:a1b2c3d4e5f6a1b2c3d4e5f6a1b2c3d4e5f6a1b2c3d4e5f6a1b2c3d4e5f6a1b2';

    private RuntimeCveWatcher $watcher;

    protected function setUp(): void
    {
        $this->watcher = new RuntimeCveWatcher();
    }

    public function test_new_advisory_emitted(): void
    {
        $previous = $this->report([
            $this->vuln('CVE-2024-0001'),
        ]);
        $current = $this->report([
            $this->vuln('CVE-2024-0001'),
            $this->vuln('CVE-2024-0002'),
        ]);

        $diff = $this->watcher->diff($previous, $current);
        self::assertCount(1, $diff);
        self::assertSame('CVE-2024-0002', $diff[0]->id);
    }

    public function test_already_seen_suppressed(): void
    {
        $previous = $this->report([
            $this->vuln('CVE-2024-0001'),
        ]);
        $current = $this->report([
            $this->vuln('CVE-2024-0001'),
        ]);

        $diff = $this->watcher->diff($previous, $current);
        self::assertSame([], $diff);
    }

    public function test_resolved_advisory_not_re_emitted(): void
    {
        $previous = $this->report([
            $this->vuln('CVE-2024-0001'),
            $this->vuln('CVE-2024-0002'),
        ]);
        $current = $this->report([
            $this->vuln('CVE-2024-0001'),
        ]);

        $diff = $this->watcher->diff($previous, $current);
        self::assertSame([], $diff);
    }

    public function test_null_previous_emits_all(): void
    {
        $current = $this->report([
            $this->vuln('CVE-2024-0001'),
            $this->vuln('CVE-2024-0002'),
        ]);

        $diff = $this->watcher->diff(null, $current);
        self::assertCount(2, $diff);
    }

    public function test_empty_to_empty(): void
    {
        $previous = $this->report([]);
        $current = $this->report([]);

        $diff = $this->watcher->diff($previous, $current);
        self::assertSame([], $diff);
    }

    private function vuln(string $id): Vulnerability
    {
        return new Vulnerability($id, 'pkg', '1.0', '1.1', Severity::High, false);
    }

    /** @param list<Vulnerability> $vulns */
    private function report(array $vulns): VulnerabilityReport
    {
        return new VulnerabilityReport(
            new ArtifactDigest(self::DIGEST),
            'trivy',
            new \DateTimeImmutable(),
            $vulns,
        );
    }
}
