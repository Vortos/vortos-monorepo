<?php
declare(strict_types=1);

namespace Vortos\Tests\Auth\Quota;

use PHPUnit\Framework\TestCase;
use Vortos\Auth\Quota\Attribute\RequiresQuota;

enum TestQuota: string { case Exports = 'exports'; }

final class RequiresQuotaAttributeTest extends TestCase
{
    public function test_accepts_string(): void
    {
        $attr = new RequiresQuota('exports');
        $this->assertSame('exports', $attr->quota);
    }

    public function test_accepts_backed_enum(): void
    {
        $attr = new RequiresQuota(TestQuota::Exports);
        $this->assertSame('exports', $attr->quota);
    }

    public function test_default_cost_is_one(): void
    {
        $attr = new RequiresQuota('exports');
        $this->assertSame(1, $attr->cost);
    }

    public function test_custom_cost(): void
    {
        $attr = new RequiresQuota('exports', cost: 5);
        $this->assertSame(5, $attr->cost);
    }
}
