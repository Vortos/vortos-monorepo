<?php
declare(strict_types=1);

namespace Vortos\Tests\Authorization\Ownership;

use PHPUnit\Framework\TestCase;
use Vortos\Authorization\Ownership\Attribute\RequiresOwnership;
use Vortos\Authorization\Ownership\Attribute\RequiresOwnershipOrPermission;

enum OwnershipTestPermission: string { case DeleteAny = 'documents.delete.any'; }

final class RequiresOwnershipAttributeTest extends TestCase
{
    public function test_stores_policy_class(): void
    {
        $attr = new RequiresOwnership('App\Policy\DocumentOwnershipPolicy');
        $this->assertSame('App\Policy\DocumentOwnershipPolicy', $attr->policy);
    }

    public function test_or_permission_stores_policy_and_override_string(): void
    {
        $attr = new RequiresOwnershipOrPermission('App\Policy\DocumentOwnershipPolicy', 'documents.delete.any');
        $this->assertSame('App\Policy\DocumentOwnershipPolicy', $attr->policy);
        $this->assertSame('documents.delete.any', $attr->override);
    }

    public function test_or_permission_accepts_backed_enum_override(): void
    {
        $attr = new RequiresOwnershipOrPermission('App\Policy\DocumentOwnershipPolicy', OwnershipTestPermission::DeleteAny);
        $this->assertSame('documents.delete.any', $attr->override);
    }
}
