<?php
declare(strict_types=1);

namespace Vortos\Tests\Authorization\Scope;

use PHPUnit\Framework\TestCase;
use Vortos\Authorization\Scope\Contract\ScopedPermissionStoreInterface;
use Vortos\Authorization\Scope\ScopedAuthorizationManager;

enum ScopeTestPermission: string { case DocumentsEdit = 'documents.edit'; }

final class ScopedAuthorizationManagerTest extends TestCase
{
    private ScopedPermissionStoreInterface $store;
    private ScopedAuthorizationManager $manager;

    protected function setUp(): void
    {
        $this->store = $this->createMock(ScopedPermissionStoreInterface::class);
        $this->manager = new ScopedAuthorizationManager($this->store);
    }

    public function test_for_scope_returns_context(): void
    {
        $context = $this->manager->forScope('org', 'org-123');
        $this->assertNotNull($context);
    }

    public function test_context_grant_calls_store(): void
    {
        $this->store->expects($this->once())
            ->method('grant')
            ->with('user-456', 'org', 'org-123', 'documents.edit', null);

        $this->manager->forScope('org', 'org-123')->grant('user-456', 'documents.edit');
    }

    public function test_context_grant_with_backed_enum(): void
    {
        $this->store->expects($this->once())
            ->method('grant')
            ->with('user-456', 'org', 'org-123', 'documents.edit', null);

        $this->manager->forScope('org', 'org-123')->grant('user-456', ScopeTestPermission::DocumentsEdit);
    }

    public function test_context_revoke_calls_store(): void
    {
        $this->store->expects($this->once())
            ->method('revoke')
            ->with('user-456', 'org', 'org-123', 'documents.edit');

        $this->manager->forScope('org', 'org-123')->revoke('user-456', 'documents.edit');
    }

    public function test_context_has_calls_store(): void
    {
        $this->store->method('has')->willReturn(true);
        $result = $this->manager->forScope('org', 'org-123')->has('user-456', 'documents.edit');
        $this->assertTrue($result);
    }

    public function test_context_revoke_all_calls_store(): void
    {
        $this->store->expects($this->once())
            ->method('revokeAll')
            ->with('user-456', 'org', 'org-123');

        $this->manager->forScope('org', 'org-123')->revokeAll('user-456');
    }

    public function test_different_scopes_are_independent(): void
    {
        $this->store->expects($this->exactly(2))->method('grant');
        $this->manager->forScope('org', 'org-1')->grant('user-1', 'edit');
        $this->manager->forScope('team', 'team-1')->grant('user-1', 'edit');
    }

    public function test_grant_with_expiry(): void
    {
        $expiry = new \DateTimeImmutable('+30 days');
        $this->store->expects($this->once())
            ->method('grant')
            ->with('user-456', 'org', 'org-123', 'documents.edit', $expiry);

        $this->manager->forScope('org', 'org-123')->grant('user-456', 'documents.edit', $expiry);
    }
}
