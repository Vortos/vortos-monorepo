<?php
declare(strict_types=1);

namespace Vortos\Tests\Authorization\Temporal;

use PHPUnit\Framework\TestCase;
use Vortos\Authorization\Temporal\Contract\TemporalPermissionStoreInterface;
use Vortos\Authorization\Temporal\TemporalGrantBuilder;

final class TemporalGrantBuilderTest extends TestCase
{
    public function test_until_calls_store_grant(): void
    {
        $store = $this->createMock(TemporalPermissionStoreInterface::class);
        $expiry = new \DateTimeImmutable('+30 days');
        $store->expects($this->once())->method('grant')->with('user-1', 'beta.feature', $expiry);

        $builder = new TemporalGrantBuilder($store, 'user-1', 'beta.feature');
        $builder->until($expiry);
    }

    public function test_for_days_sets_correct_expiry(): void
    {
        $store = $this->createMock(TemporalPermissionStoreInterface::class);
        $before = new \DateTimeImmutable('+29 days 23 hours');
        $after = new \DateTimeImmutable('+30 days 1 hour');

        $store->expects($this->once())
            ->method('grant')
            ->willReturnCallback(function(string $userId, string $permission, \DateTimeImmutable $expiry)
                use ($before, $after) {
                $this->assertGreaterThan($before, $expiry);
                $this->assertLessThan($after, $expiry);
            });

        $builder = new TemporalGrantBuilder($store, 'user-1', 'beta.feature');
        $builder->forDays(30);
    }

    public function test_for_hours_sets_correct_expiry(): void
    {
        $store = $this->createMock(TemporalPermissionStoreInterface::class);
        $store->expects($this->once())->method('grant');

        $builder = new TemporalGrantBuilder($store, 'user-1', 'beta.feature');
        $builder->forHours(24);
    }
}
