<?php
declare(strict_types=1);

namespace Vortos\Auth\Tests\Quota;

use PHPUnit\Framework\TestCase;
use Vortos\Auth\Identity\AnonymousIdentity;
use Vortos\Auth\Identity\UserIdentity;
use Vortos\Auth\Quota\QuotaSubjectProvenance;
use Vortos\Auth\Quota\Resolver\GlobalQuotaResolver;

final class GlobalQuotaResolverTest extends TestCase
{
    private GlobalQuotaResolver $resolver;

    protected function setUp(): void
    {
        $this->resolver = new GlobalQuotaResolver();
    }

    public function test_bucket_is_global(): void
    {
        $this->assertSame('global', $this->resolver->bucket());
    }

    public function test_resolve_returns_global_for_authenticated(): void
    {
        $identity = new UserIdentity('user-1', ['ROLE_USER'], []);
        $this->assertSame('global', $this->resolver->resolve($identity));
    }

    public function test_resolve_returns_global_for_anonymous(): void
    {
        $identity = new AnonymousIdentity();
        $this->assertSame('global', $this->resolver->resolve($identity));
    }

    public function test_does_not_require_authentication(): void
    {
        $this->assertFalse($this->resolver->requiresAuthentication());
    }

    public function test_provenance_is_server_verified(): void
    {
        $this->assertSame(QuotaSubjectProvenance::ServerVerified, $this->resolver->provenance());
    }
}
