<?php

declare(strict_types=1);

namespace Vortos\Security\Tests\SupplyChain\Unit\Model\Attestation;

use PHPUnit\Framework\TestCase;
use Vortos\Security\SupplyChain\Model\Attestation\CveGateDecision;

final class CveGateDecisionTest extends TestCase
{
    public function test_passed(): void
    {
        $decision = CveGateDecision::passed();
        self::assertTrue($decision->pass);
        self::assertSame([], $decision->reasons);
        self::assertSame([], $decision->offendingCves);
    }

    public function test_failed(): void
    {
        $decision = CveGateDecision::failed(['critical CVE found'], ['CVE-2024-1234']);
        self::assertFalse($decision->pass);
        self::assertSame(['critical CVE found'], $decision->reasons);
        self::assertSame(['CVE-2024-1234'], $decision->offendingCves);
    }

    public function test_failed_rejects_empty_reasons(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        CveGateDecision::failed([], []);
    }

    public function test_to_array(): void
    {
        $decision = CveGateDecision::failed(['reason'], ['CVE-1']);
        $arr = $decision->toArray();
        self::assertFalse($arr['pass']);
        self::assertSame(['reason'], $arr['reasons']);
        self::assertSame(['CVE-1'], $arr['offending_cves']);
    }
}
