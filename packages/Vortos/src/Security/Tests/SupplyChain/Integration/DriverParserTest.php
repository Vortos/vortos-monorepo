<?php

declare(strict_types=1);

namespace Vortos\Security\Tests\SupplyChain\Integration;

use PHPUnit\Framework\TestCase;
use Vortos\Security\SupplyChain\Driver\Cisa\CisaKevCatalogProvider;
use Vortos\Security\SupplyChain\Driver\Trivy\TrivyVulnerabilityScanner;
use Vortos\Security\SupplyChain\Model\ArtifactDigest;
use Vortos\Security\SupplyChain\Model\ArtifactRef;
use Vortos\Security\SupplyChain\Model\Sbom\SbomFormat;
use Vortos\Security\SupplyChain\Driver\Syft\SyftSbomGenerator;
use Vortos\Security\SupplyChain\Driver\Process\ProcessOutput;
use Vortos\Security\SupplyChain\Driver\Process\ProcessRunnerInterface;

final class DriverParserTest extends TestCase
{
    private const DIGEST = 'sha256:a1b2c3d4e5f6a1b2c3d4e5f6a1b2c3d4e5f6a1b2c3d4e5f6a1b2c3d4e5f6a1b2';
    private const FIXTURES_DIR = __DIR__ . '/../Fixtures/';

    public function test_trivy_parser_with_fixture(): void
    {
        $json = file_get_contents(self::FIXTURES_DIR . 'trivy_report.json');
        $ref = new ArtifactRef('test/image', new ArtifactDigest(self::DIGEST));
        $data = json_decode($json, true, 512, \JSON_THROW_ON_ERROR);

        $report = TrivyVulnerabilityScanner::parseReport($data, $ref);

        self::assertSame('trivy', $report->scanner);
        self::assertCount(3, $report->vulnerabilities);
        self::assertSame('CVE-2024-0001', $report->vulnerabilities[0]->id);
        self::assertSame('3.1.4-r2', $report->vulnerabilities[0]->fixedVersion);
        self::assertNull($report->vulnerabilities[1]->fixedVersion);
    }

    public function test_trivy_parser_empty_results(): void
    {
        $ref = new ArtifactRef('test/image', new ArtifactDigest(self::DIGEST));
        $report = TrivyVulnerabilityScanner::parseReport(['Results' => []], $ref);
        self::assertTrue($report->isEmpty());
    }

    public function test_syft_cyclonedx_parser_with_fixture(): void
    {
        $json = file_get_contents(self::FIXTURES_DIR . 'cyclonedx_sbom.json');
        $runner = $this->fakeRunner($json);
        $generator = new SyftSbomGenerator($runner);

        $ref = new ArtifactRef('test/image', new ArtifactDigest(self::DIGEST));
        $doc = $generator->generate($ref, SbomFormat::CycloneDxJson);

        self::assertSame(SbomFormat::CycloneDxJson, $doc->format);
        self::assertCount(2, $doc->components);
        self::assertSame('alpine', $doc->components[0]->name);
        self::assertSame('MIT', $doc->components[0]->licenses[0]);
    }

    public function test_syft_spdx_parser_with_fixture(): void
    {
        $json = file_get_contents(self::FIXTURES_DIR . 'spdx_sbom.json');
        $runner = $this->fakeRunner($json);
        $generator = new SyftSbomGenerator($runner);

        $ref = new ArtifactRef('test/image', new ArtifactDigest(self::DIGEST));
        $doc = $generator->generate($ref, SbomFormat::SpdxJson);

        self::assertSame(SbomFormat::SpdxJson, $doc->format);
        self::assertCount(2, $doc->components);
        self::assertSame('alpine', $doc->components[0]->name);
        self::assertSame('pkg:oci/alpine@sha256:abc', $doc->components[0]->purl);
    }

    public function test_syft_non_zero_exit(): void
    {
        $runner = new class implements ProcessRunnerInterface {
            public function run(array $command, array $env = [], ?int $timeoutSeconds = null): ProcessOutput
            {
                return new ProcessOutput(1, '', 'syft error');
            }
        };

        $generator = new SyftSbomGenerator($runner);
        $ref = new ArtifactRef('test/image', new ArtifactDigest(self::DIGEST));

        $this->expectException(\Vortos\Security\SupplyChain\Driver\Process\ProcessFailedException::class);
        $generator->generate($ref, SbomFormat::CycloneDxJson);
    }

    public function test_syft_invalid_json(): void
    {
        $runner = $this->fakeRunner('not-json');
        $generator = new SyftSbomGenerator($runner);

        $ref = new ArtifactRef('test/image', new ArtifactDigest(self::DIGEST));
        $this->expectException(\Vortos\Security\SupplyChain\Model\ScanFailedException::class);
        $generator->generate($ref, SbomFormat::CycloneDxJson);
    }

    public function test_cisa_kev_parser_with_fixture(): void
    {
        $json = file_get_contents(self::FIXTURES_DIR . 'kev_catalog.json');
        $sourceHash = 'sha256:' . hash('sha256', $json);

        $catalog = CisaKevCatalogProvider::parseCatalog($json, $sourceHash);

        self::assertSame(3, $catalog->count());
        self::assertTrue($catalog->contains('CVE-2024-0001'));
        self::assertTrue($catalog->contains('CVE-2024-0099'));
        self::assertFalse($catalog->contains('CVE-2024-9999'));
    }

    public function test_cisa_kev_parser_invalid_json(): void
    {
        $this->expectException(\Vortos\Security\SupplyChain\Model\KevCatalogUnavailableException::class);
        CisaKevCatalogProvider::parseCatalog('not-json', 'sha256:bad');
    }

    public function test_cisa_kev_parser_missing_vulnerabilities_key(): void
    {
        $this->expectException(\Vortos\Security\SupplyChain\Model\KevCatalogUnavailableException::class);
        CisaKevCatalogProvider::parseCatalog('{}', 'sha256:bad');
    }

    private function fakeRunner(string $stdout): ProcessRunnerInterface
    {
        return new class($stdout) implements ProcessRunnerInterface {
            public function __construct(private readonly string $stdout) {}

            public function run(array $command, array $env = [], ?int $timeoutSeconds = null): ProcessOutput
            {
                return new ProcessOutput(0, $this->stdout, '');
            }
        };
    }
}
