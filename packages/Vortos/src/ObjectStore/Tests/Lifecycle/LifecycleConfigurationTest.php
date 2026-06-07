<?php

declare(strict_types=1);

namespace Vortos\ObjectStore\Tests\Lifecycle;

use PHPUnit\Framework\TestCase;
use Vortos\ObjectStore\Lifecycle\LifecycleConfiguration;
use Vortos\ObjectStore\Lifecycle\LifecycleRule;

final class LifecycleConfigurationTest extends TestCase
{
    public function test_upserts_managed_rule_and_preserves_unmanaged_rules(): void
    {
        $current = new LifecycleConfiguration([
            [
                'ID' => 'user-rule',
                'Status' => 'Enabled',
                'Filter' => ['Prefix' => 'archive/'],
                'Expiration' => ['Days' => 365],
            ],
        ]);

        $desired = $current->withRule(LifecycleRule::temporaryUploadExpiry('managed', 'tmp', 86400));

        $this->assertTrue($desired->hasRule('user-rule'));
        $this->assertTrue($desired->hasRule('managed'));
        $this->assertCount(2, $desired->rules());
    }

    public function test_updating_managed_rule_does_not_duplicate_it(): void
    {
        $current = new LifecycleConfiguration([
            LifecycleRule::temporaryUploadExpiry('managed', 'old-tmp', 86400)->toS3Rule(),
        ]);

        $desired = $current->withRule(LifecycleRule::temporaryUploadExpiry('managed', 'tmp', 172800));

        $this->assertCount(1, $desired->rules());
        $this->assertSame('tmp/', $desired->rule('managed')['Filter']['Prefix']);
        $this->assertSame(2, $desired->rule('managed')['Expiration']['Days']);
    }

    public function test_removing_managed_rule_preserves_unmanaged_rules(): void
    {
        $current = new LifecycleConfiguration([
            LifecycleRule::temporaryUploadExpiry('managed', 'tmp', 86400)->toS3Rule(),
            [
                'ID' => 'user-rule',
                'Status' => 'Enabled',
                'Filter' => ['Prefix' => 'archive/'],
                'Expiration' => ['Days' => 365],
            ],
        ]);

        $desired = $current->withoutRule('managed');

        $this->assertFalse($desired->hasRule('managed'));
        $this->assertTrue($desired->hasRule('user-rule'));
    }
}
