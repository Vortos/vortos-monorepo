<?php
declare(strict_types=1);

namespace Vortos\Tests\Auth\Audit;

use PHPUnit\Framework\TestCase;
use Vortos\Auth\Audit\Attribute\AuditLog;

enum TestAuditAction: string { case DocumentViewed = 'document.viewed'; }

final class AuditLogAttributeTest extends TestCase
{
    public function test_accepts_string_action(): void
    {
        $attr = new AuditLog('document.viewed');
        $this->assertSame('document.viewed', $attr->action);
    }

    public function test_accepts_backed_enum_action(): void
    {
        $attr = new AuditLog(TestAuditAction::DocumentViewed);
        $this->assertSame('document.viewed', $attr->action);
    }

    public function test_default_include_is_empty(): void
    {
        $attr = new AuditLog('document.viewed');
        $this->assertEmpty($attr->include);
    }

    public function test_include_stores_param_names(): void
    {
        $attr = new AuditLog('document.deleted', include: ['id', 'reason']);
        $this->assertSame(['id', 'reason'], $attr->include);
    }
}
