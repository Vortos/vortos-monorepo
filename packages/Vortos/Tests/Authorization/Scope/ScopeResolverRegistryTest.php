<?php
declare(strict_types=1);

namespace Vortos\Tests\Authorization\Scope;

use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Vortos\Authorization\Scope\Contract\ScopeResolverInterface;
use Vortos\Authorization\Scope\ScopeResolverRegistry;

final class OrgResolver implements ScopeResolverInterface
{
    public function getScopeName(): string { return 'org'; }
    public function resolveScope(Request $request): string
    {
        return $request->attributes->get('org_id', 'default-org');
    }
}

final class TeamResolver implements ScopeResolverInterface
{
    public function getScopeName(): string { return 'team'; }
    public function resolveScope(Request $request): string
    {
        return $request->attributes->get('team_id', 'default-team');
    }
}

final class ScopeResolverRegistryTest extends TestCase
{
    public function test_resolves_scope_by_name(): void
    {
        $registry = new ScopeResolverRegistry(['org' => new OrgResolver()]);
        $request = Request::create('/test');
        $request->attributes->set('org_id', 'org-123');
        $this->assertSame('org-123', $registry->resolve('org', $request));
    }

    public function test_has_returns_true_for_registered_scope(): void
    {
        $registry = new ScopeResolverRegistry(['org' => new OrgResolver()]);
        $this->assertTrue($registry->has('org'));
    }

    public function test_has_returns_false_for_unknown_scope(): void
    {
        $registry = new ScopeResolverRegistry([]);
        $this->assertFalse($registry->has('unknown'));
    }

    public function test_throws_for_unknown_scope(): void
    {
        $registry = new ScopeResolverRegistry([]);
        $this->expectException(\InvalidArgumentException::class);
        $registry->resolve('unknown', Request::create('/test'));
    }

    public function test_multiple_resolvers(): void
    {
        $registry = new ScopeResolverRegistry([
            'org'  => new OrgResolver(),
            'team' => new TeamResolver(),
        ]);

        $request = Request::create('/test');
        $request->attributes->set('org_id', 'org-1');
        $request->attributes->set('team_id', 'team-1');

        $this->assertSame('org-1', $registry->resolve('org', $request));
        $this->assertSame('team-1', $registry->resolve('team', $request));
    }
}
