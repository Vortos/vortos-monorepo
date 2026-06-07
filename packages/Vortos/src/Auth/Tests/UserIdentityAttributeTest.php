<?php
declare(strict_types=1);

namespace Vortos\Auth\Tests;

use PHPUnit\Framework\TestCase;
use Vortos\Auth\Identity\AnonymousIdentity;
use Vortos\Auth\Identity\UserIdentity;

final class UserIdentityAttributeTest extends TestCase
{
    public function test_get_attribute_returns_value(): void
    {
        $identity = new UserIdentity('user-1', [], ['plan' => 'pro']);
        $this->assertSame('pro', $identity->getAttribute('plan'));
    }

    public function test_get_attribute_returns_default_when_missing(): void
    {
        $identity = new UserIdentity('user-1', []);
        $this->assertSame('free', $identity->getAttribute('plan', 'free'));
    }

    public function test_get_attribute_returns_null_default(): void
    {
        $identity = new UserIdentity('user-1', []);
        $this->assertNull($identity->getAttribute('plan'));
    }

    public function test_multiple_attributes(): void
    {
        $identity = new UserIdentity('user-1', [], ['plan' => 'pro', 'org_id' => 'org-123']);
        $this->assertSame('pro', $identity->getAttribute('plan'));
        $this->assertSame('org-123', $identity->getAttribute('org_id'));
    }

    public function test_anonymous_identity_get_attribute_returns_default(): void
    {
        $identity = new AnonymousIdentity();
        $this->assertSame('free', $identity->getAttribute('plan', 'free'));
        $this->assertNull($identity->getAttribute('plan'));
    }

    public function test_get_claims_returns_all_attributes(): void
    {
        $identity = new UserIdentity('user-1', [], ['plan' => 'pro', 'org_id' => 'org-123']);
        $this->assertSame(['plan' => 'pro', 'org_id' => 'org-123'], $identity->getClaims());
    }

    public function test_get_claims_returns_empty_array_when_no_attributes(): void
    {
        $identity = new UserIdentity('user-1', []);
        $this->assertSame([], $identity->getClaims());
    }

    public function test_anonymous_identity_get_claims_returns_empty_array(): void
    {
        $this->assertSame([], (new AnonymousIdentity())->getClaims());
    }
}
