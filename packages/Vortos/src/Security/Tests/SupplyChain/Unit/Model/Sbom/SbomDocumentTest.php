<?php

declare(strict_types=1);

namespace Vortos\Security\Tests\SupplyChain\Unit\Model\Sbom;

use PHPUnit\Framework\TestCase;
use Vortos\Security\SupplyChain\Model\Sbom\SbomComponent;
use Vortos\Security\SupplyChain\Model\Sbom\SbomDocument;
use Vortos\Security\SupplyChain\Model\Sbom\SbomFormat;

final class SbomDocumentTest extends TestCase
{
    public function test_content_hash_is_stable(): void
    {
        $doc = $this->sampleDoc();
        self::assertSame($doc->contentHash(), $doc->contentHash());
    }

    public function test_content_hash_is_order_independent(): void
    {
        $a = new SbomDocument(SbomFormat::CycloneDxJson, '1.5', [
            new SbomComponent('alpha', '1.0'),
            new SbomComponent('beta', '2.0'),
        ]);
        $b = new SbomDocument(SbomFormat::CycloneDxJson, '1.5', [
            new SbomComponent('beta', '2.0'),
            new SbomComponent('alpha', '1.0'),
        ]);

        self::assertSame($a->contentHash(), $b->contentHash());
    }

    public function test_component_count(): void
    {
        self::assertSame(2, $this->sampleDoc()->componentCount());
    }

    public function test_round_trips_via_array(): void
    {
        $doc = $this->sampleDoc();
        $restored = SbomDocument::fromArray($doc->toArray());

        self::assertSame($doc->format, $restored->format);
        self::assertSame($doc->specVersion, $restored->specVersion);
        self::assertCount(2, $restored->components);
    }

    public function test_rejects_empty_spec_version(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new SbomDocument(SbomFormat::CycloneDxJson, '', []);
    }

    public function test_empty_components(): void
    {
        $doc = new SbomDocument(SbomFormat::SpdxJson, '2.3', []);
        self::assertSame(0, $doc->componentCount());
    }

    private function sampleDoc(): SbomDocument
    {
        return new SbomDocument(SbomFormat::CycloneDxJson, '1.5', [
            new SbomComponent('alpha', '1.0', 'pkg:npm/alpha@1.0', ['MIT']),
            new SbomComponent('beta', '2.0'),
        ]);
    }
}
