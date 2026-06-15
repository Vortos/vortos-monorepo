<?php

declare(strict_types=1);

namespace Vortos\Tenant\Tests;

use PHPUnit\Framework\TestCase;
use Vortos\Tenant\Rls\TenantRlsPolicy;

final class TenantRlsPolicyTest extends TestCase
{
    public function test_enable_sql_creates_policy_with_session_predicate(): void
    {
        $sql = (new TenantRlsPolicy('invoices'))->enableSql();

        $this->assertContains('ALTER TABLE invoices ENABLE ROW LEVEL SECURITY', $sql);
        $this->assertContains('ALTER TABLE invoices FORCE ROW LEVEL SECURITY', $sql);

        $policy = $sql[2];
        $this->assertStringContainsString('CREATE POLICY tenant_isolation ON invoices', $policy);
        $this->assertStringContainsString("current_setting('app.current_tenant', true)", $policy);
        $this->assertStringContainsString('tenant_id::text', $policy);
        $this->assertStringContainsString('USING', $policy);
        $this->assertStringContainsString('WITH CHECK', $policy);
    }

    public function test_custom_column_and_setting(): void
    {
        $policy = (new TenantRlsPolicy('orders', 'org_id', 'app.org'))->enableSql()[2];

        $this->assertStringContainsString('org_id::text', $policy);
        $this->assertStringContainsString("current_setting('app.org', true)", $policy);
    }

    public function test_column_default_sql(): void
    {
        $sql = (new TenantRlsPolicy('invoices'))->columnDefaultSql();

        $this->assertStringContainsString('ALTER TABLE invoices ALTER COLUMN tenant_id SET DEFAULT', $sql);
        $this->assertStringContainsString("current_setting('app.current_tenant', true)", $sql);
    }

    public function test_disable_sql_drops_policy_and_rls(): void
    {
        $sql = (new TenantRlsPolicy('invoices'))->disableSql();

        $this->assertContains('DROP POLICY IF EXISTS tenant_isolation ON invoices', $sql);
        $this->assertContains('ALTER TABLE invoices DISABLE ROW LEVEL SECURITY', $sql);
    }

    public function test_empty_table_rejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new TenantRlsPolicy('');
    }
}
