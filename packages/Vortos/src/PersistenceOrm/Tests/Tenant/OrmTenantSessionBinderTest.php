<?php

declare(strict_types=1);

namespace Vortos\PersistenceOrm\Tests\Tenant;

use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Vortos\PersistenceOrm\Tenant\OrmTenantSessionBinder;
use Vortos\Tenant\TenantContext;

final class OrmTenantSessionBinderTest extends TestCase
{
    public function test_bind_session_sets_tenant_as_session_scope(): void
    {
        $context = new TenantContext();
        $context->set('acme');

        $this->binder($context, $captured)->bindSession();

        $this->assertSame(['app.current_tenant', 'acme', 0], $captured);
    }

    public function test_bind_local_sets_tenant_as_transaction_scope(): void
    {
        $context = new TenantContext();
        $context->set('acme');

        $this->binder($context, $captured)->bindLocal();

        $this->assertSame(['app.current_tenant', 'acme', 1], $captured);
    }

    public function test_no_tenant_resets_the_variable(): void
    {
        $this->binder(new TenantContext(), $captured)->bindSession();

        $this->assertSame(['app.current_tenant', '', 0], $captured);
    }

    public function test_system_scope_resets_the_variable(): void
    {
        $context = new TenantContext();
        $context->set('acme');

        $binder = $this->binder($context, $captured);
        $context->runAsSystem(static fn() => $binder->bindSession());

        $this->assertSame(['app.current_tenant', '', 0], $captured);
    }

    private function binder(TenantContext $context, ?array &$captured): OrmTenantSessionBinder
    {
        $captured = null;
        $connection = $this->createMock(Connection::class);
        $connection->method('executeStatement')->willReturnCallback(
            static function (string $sql, array $params) use (&$captured): int {
                $captured = $params;
                return 1;
            },
        );

        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('getConnection')->willReturn($connection);

        return new OrmTenantSessionBinder($em, $context);
    }
}
