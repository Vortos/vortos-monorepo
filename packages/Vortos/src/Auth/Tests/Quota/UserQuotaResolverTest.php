<?php
declare(strict_types=1);

namespace Vortos\Auth\Tests\Quota;

use PHPUnit\Framework\TestCase;
use Vortos\Auth\Identity\AnonymousIdentity;
use Vortos\Auth\Identity\UserIdentity;
use Vortos\Auth\Quota\QuotaSubjectProvenance;
use Vortos\Auth\Quota\Resolver\UserQuotaResolver;

final class UserQuotaResolverTest extends TestCase
{
    private UserQuotaResolver $resolver;

    protected function setUp(): void
    {
        $this->resolver = new UserQuotaResolver();
    }

    public function test_bucket_is_user(): void
    {
        $this->assertSame('user', $this->resolver->bucket());
    }

    public function test_resolve_returns_user_id(): void
    {
        $identity = new UserIdentity('user-42', ['ROLE_USER'], []);
        $this->assertSame('user-42', $this->resolver->resolve($identity));
    }

    public function test_resolve_returns_empty_for_anonymous(): void
    {
        $identity = new AnonymousIdentity();
        $this->assertSame('', $this->resolver->resolve($identity));
    }

    public function test_requires_authentication(): void
    {
        $this->assertTrue($this->resolver->requiresAuthentication());
    }

    public function test_provenance_is_server_verified(): void
    {
        $this->assertSame(QuotaSubjectProvenance::ServerVerified, $this->resolver->provenance());
    }
}
