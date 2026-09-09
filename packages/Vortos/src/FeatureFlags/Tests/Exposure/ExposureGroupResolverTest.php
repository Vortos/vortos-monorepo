<?php

declare(strict_types=1);

namespace Vortos\FeatureFlags\Tests\Exposure;

use PHPUnit\Framework\TestCase;
use Vortos\FeatureFlags\Exposure\ExposureGroupResolver;
use Vortos\FeatureFlags\FlagContext;

final class ExposureGroupResolverTest extends TestCase
{
    public function test_resolves_the_default_organization_association(): void
    {
        $resolver = new ExposureGroupResolver();

        $groups = $resolver->resolve(new FlagContext('u1', [], ['tenantId' => 'org-7']));

        $this->assertSame(['organization' => 'org-7'], $groups);
    }

    public function test_reads_only_the_trusted_zone(): void
    {
        // The security property. `X-Vortos-Flag-Context` is attacker-controlled: if the
        // untrusted zone could supply a group key, any user could attribute their exposures
        // to another tenant and pollute that org's rollout numbers.
        $resolver = new ExposureGroupResolver();

        $spoofed = new FlagContext('u1', ['tenantId' => 'victim-org'], [], ['tenantId' => 'victim-org']);

        $this->assertSame([], $resolver->resolve($spoofed));
    }

    public function test_supports_multiple_associations(): void
    {
        $resolver = new ExposureGroupResolver(['organization' => 'tenantId', 'team' => 'accountId']);

        $groups = $resolver->resolve(new FlagContext('u1', [], ['tenantId' => 'org-7', 'accountId' => 'team-3']));

        $this->assertSame(['organization' => 'org-7', 'team' => 'team-3'], $groups);
    }

    public function test_absent_and_unusable_values_are_omitted_not_blank(): void
    {
        $resolver = new ExposureGroupResolver(['organization' => 'tenantId', 'team' => 'accountId', 'other' => 'nested']);

        $groups = $resolver->resolve(new FlagContext('u1', [], ['tenantId' => '', 'nested' => ['a' => 'b']]));

        $this->assertSame([], $groups, 'a blank or non-scalar association is no association');
    }

    public function test_numeric_ids_are_stringified(): void
    {
        $resolver = new ExposureGroupResolver();

        $groups = $resolver->resolve(new FlagContext('u1', [], ['tenantId' => 42]));

        $this->assertSame(['organization' => '42'], $groups);
    }
}
