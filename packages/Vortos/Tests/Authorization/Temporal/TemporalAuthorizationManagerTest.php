<?php
declare(strict_types=1);

namespace Vortos\Tests\Authorization\Temporal;

use PHPUnit\Framework\TestCase;
use Vortos\Authorization\Temporal\Contract\TemporalPermissionStoreInterface;
use Vortos\Authorization\Temporal\TemporalAuthorizationManager;
use Vortos\Authorization\Temporal\TemporalGrantBuilder;

final class TemporalAuthorizationManagerTest extends TestCase
{
    private TemporalPermissionStoreInterface $store;
    private TemporalAuthorizationManager $manager;

    protected function setUp(): void
    {
        $this->store = $this->createMock(TemporalPermissionStoreInterface::class);
        $this->manager = new TemporalAuthorizationManager($this->store);
    }

    public function test_grant_returns_builder(): void
    {
        $builder = $this->manager->grant('user-1', 'beta.feature');
        $this->assertInstanceOf(TemporalGrantBuilder::class, $builder);
    }

    public function test_grant_with_backed_enum(): void
    {
        $this->store->expects($this->once())
            ->method('grant')
            ->with('user-1', 'beta.feature', $this->anything());

        $this->manager->grant('user-1', 'beta.feature')->forDays(30);
    }

    public function test_revoke_calls_store(): void
    {
        $this->store->expects($this->once())
            ->method('revoke')
            ->with('user-1', 'beta.feature');

        $this->manager->revoke('user-1', 'beta.feature');
    }

    public function test_is_valid_returns_store_result(): void
    {
        $this->store->method('isValid')->willReturn(true);
        $this->assertTrue($this->manager->isValid('user-1', 'beta.feature'));
    }

    public function test_is_valid_returns_false_when_expired(): void
    {
        $this->store->method('isValid')->willReturn(false);
        $this->assertFalse($this->manager->isValid('user-1', 'beta.feature'));
    }

    public function test_get_expiry_returns_datetime(): void
    {
        $expiry = new \DateTimeImmutable('+30 days');
        $this->store->method('getExpiry')->willReturn($expiry);
        $this->assertSame($expiry, $this->manager->getExpiry('user-1', 'beta.feature'));
    }

    public function test_get_expiry_returns_null_when_not_set(): void
    {
        $this->store->method('getExpiry')->willReturn(null);
        $this->assertNull($this->manager->getExpiry('user-1', 'beta.feature'));
    }
}
