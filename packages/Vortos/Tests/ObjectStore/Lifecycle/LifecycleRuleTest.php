<?php

declare(strict_types=1);

namespace Vortos\Tests\ObjectStore\Lifecycle;

use PHPUnit\Framework\TestCase;
use Vortos\ObjectStore\Exception\ObjectStoreConfigurationException;
use Vortos\ObjectStore\Lifecycle\LifecycleRule;

final class LifecycleRuleTest extends TestCase
{
    public function test_temporary_upload_rule_uses_normalized_prefix_and_day_expiration(): void
    {
        $rule = LifecycleRule::temporaryUploadExpiry('managed', '/tmp/', 86400);

        $this->assertSame([
            'ID' => 'managed',
            'Status' => 'Enabled',
            'Filter' => ['Prefix' => 'tmp/'],
            'Expiration' => ['Days' => 1],
        ], $rule->toS3Rule());
    }

    public function test_rejects_sub_day_ttl_by_default(): void
    {
        $this->expectException(ObjectStoreConfigurationException::class);
        LifecycleRule::temporaryUploadExpiry('managed', 'tmp', 3600);
    }

    public function test_can_round_sub_day_ttl_up_when_explicitly_enabled(): void
    {
        $rule = LifecycleRule::temporaryUploadExpiry('managed', 'tmp', 3600, roundUpMinimumLifecycleDay: true);

        $this->assertSame(1, $rule->expirationDays());
    }
}
