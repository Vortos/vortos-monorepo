<?php

declare(strict_types=1);

namespace Vortos\Deploy\Tests\Architecture;

use PHPUnit\Framework\TestCase;

final class CredentialMaterialNeverLoggedTest extends TestCase
{
    public function test_no_provider_or_lease_calls_reveal_in_logger_or_sprintf_or_exception(): void
    {
        $credentialDir = dirname(__DIR__, 2) . '/Credential';
        $violations = [];

        foreach ($this->phpFiles($credentialDir) as $file) {
            $code = (string) file_get_contents($file);
            $basename = basename($file);

            if ($basename === 'CredentialUse.php' || $basename === 'CredentialLease.php' || $basename === 'AbstractCredentialProvider.php') {
                if (preg_match('/->reveal\(\)/', $code)) {
                    $violations[] = "{$basename} calls ->reveal() — credential material must never be exposed in lease/use code";
                }
            }

            if (preg_match('/sprintf\s*\([^)]*->reveal\(\)/', $code)) {
                $violations[] = "{$basename} passes ->reveal() into sprintf()";
            }

            if (preg_match('/new\s+\\\\?(Runtime|Invalid|Domain|Logic)Exception\s*\([^)]*->reveal\(\)/', $code)) {
                $violations[] = "{$basename} passes ->reveal() into an exception constructor";
            }
        }

        $this->assertSame([], $violations, implode("\n", $violations));
    }

    public function test_governance_audit_never_contains_material(): void
    {
        $auditFile = dirname(__DIR__, 2) . '/Credential/Governance/IssuedCredentialAudit.php';
        if (!file_exists($auditFile)) {
            $this->markTestSkipped('IssuedCredentialAudit not yet created.');
        }

        $code = (string) file_get_contents($auditFile);

        $this->assertStringNotContainsString('reveal()', $code, 'Audit must never call reveal().');
        $this->assertStringNotContainsString('SecretValue', $code, 'Audit must not reference SecretValue — it carries fingerprints, never material.');
    }

    /** @return list<string> */
    private function phpFiles(string $dir): array
    {
        $files = [];
        if (!is_dir($dir)) {
            return $files;
        }

        foreach (new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \RecursiveDirectoryIterator::SKIP_DOTS),
        ) as $file) {
            if ($file->getExtension() === 'php' && !str_contains($file->getPathname(), '/Tests/')) {
                $files[] = $file->getPathname();
            }
        }

        return $files;
    }
}
