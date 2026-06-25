<?php
declare(strict_types=1);

namespace Vortos\Auth\Tests\Quota;

use PHPUnit\Framework\TestCase;
use Vortos\Auth\Quota\QuotaSubjectProvenance;

final class QuotaSubjectProvenanceTest extends TestCase
{
    public function test_server_verified_value(): void
    {
        $this->assertSame('server_verified', QuotaSubjectProvenance::ServerVerified->value);
    }

    public function test_claim_derived_value(): void
    {
        $this->assertSame('claim_derived', QuotaSubjectProvenance::ClaimDerived->value);
    }
}
