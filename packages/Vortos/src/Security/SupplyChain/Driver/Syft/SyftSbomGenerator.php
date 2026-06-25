<?php

declare(strict_types=1);

namespace Vortos\Security\SupplyChain\Driver\Syft;

use Vortos\OpsKit\Attribute\AsDriver;
use Vortos\OpsKit\Driver\Capability\CapabilityDescriptor;
use Vortos\Security\SupplyChain\Capability\SupplyChainCapabilityKey;
use Vortos\Security\SupplyChain\Driver\Process\ProcessFailedException;
use Vortos\Security\SupplyChain\Driver\Process\ProcessRunnerInterface;
use Vortos\Security\SupplyChain\Model\ArtifactRef;
use Vortos\Security\SupplyChain\Model\Sbom\SbomComponent;
use Vortos\Security\SupplyChain\Model\Sbom\SbomDocument;
use Vortos\Security\SupplyChain\Model\Sbom\SbomFormat;
use Vortos\Security\SupplyChain\Model\ScanFailedException;
use Vortos\Security\SupplyChain\Port\SbomGeneratorInterface;

#[AsDriver('syft')]
final class SyftSbomGenerator implements SbomGeneratorInterface
{
    public function __construct(
        private readonly ProcessRunnerInterface $processRunner,
        private readonly int $timeoutSeconds = 300,
    ) {}

    public function capabilities(): CapabilityDescriptor
    {
        return CapabilityDescriptor::create([
            SupplyChainCapabilityKey::Sbom->value => true,
        ]);
    }

    public function generate(ArtifactRef $ref, SbomFormat $format): SbomDocument
    {
        $outputFormat = match ($format) {
            SbomFormat::CycloneDxJson => 'cyclonedx-json',
            SbomFormat::SpdxJson => 'spdx-json',
        };

        try {
            $output = $this->processRunner->run(
                ['syft', $ref->toString(), '-o', $outputFormat, '--quiet'],
                [],
                $this->timeoutSeconds,
            );
        } catch (ProcessFailedException $e) {
            throw new ScanFailedException('syft process failed: ' . $e->getMessage(), 0, $e);
        }

        if (!$output->isSuccessful()) {
            throw ProcessFailedException::fromOutput('syft', $output);
        }

        return $this->parseOutput($output->stdout, $format);
    }

    private function parseOutput(string $json, SbomFormat $format): SbomDocument
    {
        try {
            $data = json_decode($json, true, 512, \JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new ScanFailedException('syft returned invalid JSON: ' . $e->getMessage(), 0, $e);
        }

        if (!is_array($data)) {
            throw new ScanFailedException('syft returned non-array JSON output.');
        }

        return match ($format) {
            SbomFormat::CycloneDxJson => $this->parseCycloneDx($data),
            SbomFormat::SpdxJson => $this->parseSpdx($data),
        };
    }

    /** @param array<string, mixed> $data */
    private function parseCycloneDx(array $data): SbomDocument
    {
        $components = [];
        foreach ($data['components'] ?? [] as $comp) {
            if (!is_array($comp) || !isset($comp['name'], $comp['version'])) {
                continue;
            }

            $licenses = [];
            foreach ($comp['licenses'] ?? [] as $lic) {
                if (isset($lic['license']['id'])) {
                    $licenses[] = $lic['license']['id'];
                } elseif (isset($lic['license']['name'])) {
                    $licenses[] = $lic['license']['name'];
                }
            }

            $components[] = new SbomComponent(
                name: (string) $comp['name'],
                version: (string) $comp['version'],
                purl: isset($comp['purl']) ? (string) $comp['purl'] : null,
                licenses: $licenses,
            );
        }

        return new SbomDocument(
            format: SbomFormat::CycloneDxJson,
            specVersion: (string) ($data['specVersion'] ?? $data['bomFormat'] ?? '1.5'),
            components: $components,
        );
    }

    /** @param array<string, mixed> $data */
    private function parseSpdx(array $data): SbomDocument
    {
        $components = [];
        foreach ($data['packages'] ?? [] as $pkg) {
            if (!is_array($pkg) || !isset($pkg['name'], $pkg['versionInfo'])) {
                continue;
            }

            $licenses = [];
            if (isset($pkg['licenseConcluded']) && $pkg['licenseConcluded'] !== 'NOASSERTION') {
                $licenses[] = (string) $pkg['licenseConcluded'];
            }

            $purl = null;
            foreach ($pkg['externalRefs'] ?? [] as $ref) {
                if (is_array($ref) && ($ref['referenceType'] ?? '') === 'purl' && isset($ref['referenceLocator'])) {
                    $purl = (string) $ref['referenceLocator'];
                    break;
                }
            }

            $components[] = new SbomComponent(
                name: (string) $pkg['name'],
                version: (string) $pkg['versionInfo'],
                purl: $purl,
                licenses: $licenses,
            );
        }

        return new SbomDocument(
            format: SbomFormat::SpdxJson,
            specVersion: (string) ($data['spdxVersion'] ?? '2.3'),
            components: $components,
        );
    }
}
