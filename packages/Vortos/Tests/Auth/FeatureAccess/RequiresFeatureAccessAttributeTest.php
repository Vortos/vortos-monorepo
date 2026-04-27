<?php
declare(strict_types=1);

namespace Vortos\Tests\Auth\FeatureAccess;

use PHPUnit\Framework\TestCase;
use Vortos\Auth\FeatureAccess\Attribute\RequiresFeatureAccess;

enum TestFeature: string { case BulkExport = 'api.bulk_export'; case Basic = 'api.basic'; }

final class RequiresFeatureAccessAttributeTest extends TestCase
{
    public function test_accepts_string(): void
    {
        $attr = new RequiresFeatureAccess('api.bulk_export');
        $this->assertSame('api.bulk_export', $attr->feature);
    }

    public function test_accepts_backed_enum(): void
    {
        $attr = new RequiresFeatureAccess(TestFeature::BulkExport);
        $this->assertSame('api.bulk_export', $attr->feature);
    }

    public function test_payment_required_defaults_false(): void
    {
        $attr = new RequiresFeatureAccess('api.export');
        $this->assertFalse($attr->paymentRequired);
    }

    public function test_payment_required_can_be_true(): void
    {
        $attr = new RequiresFeatureAccess('api.export', paymentRequired: true);
        $this->assertTrue($attr->paymentRequired);
    }

    public function test_is_repeatable(): void
    {
        $reflection = new \ReflectionClass(RequiresFeatureAccess::class);
        $attrs = $reflection->getAttributes(\Attribute::class);
        $flags = $attrs[0]->newInstance()->flags;
        $this->assertTrue((bool)($flags & \Attribute::IS_REPEATABLE));
    }
}
