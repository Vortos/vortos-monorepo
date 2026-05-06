<?php

declare(strict_types=1);

namespace Vortos\Tests\Foundation\Health;

use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Vortos\Foundation\Health\HealthDetailPolicy;

final class HealthDetailPolicyTest extends TestCase
{
    public function test_debug_policy_allows_details_in_dev(): void
    {
        $policy = new HealthDetailPolicy(
            policy: HealthDetailPolicy::DEBUG,
            appEnv: 'dev',
        );

        $this->assertTrue($policy->allowsDetails(new Request()));
        $this->assertTrue($policy->allowsRawErrors());
    }

    public function test_token_policy_requires_matching_header(): void
    {
        $policy = new HealthDetailPolicy(
            policy: HealthDetailPolicy::TOKEN,
            token: 'secret',
            appEnv: 'prod',
        );

        $this->assertFalse($policy->allowsDetails(new Request()));

        $request = new Request();
        $request->headers->set('X-Health-Token', 'secret');

        $this->assertTrue($policy->allowsDetails($request));
        $this->assertFalse($policy->allowsRawErrors());
    }

    public function test_never_policy_blocks_details_even_with_token(): void
    {
        $policy = new HealthDetailPolicy(
            policy: HealthDetailPolicy::NEVER,
            token: 'secret',
            appEnv: 'dev',
            appDebug: true,
        );

        $request = new Request();
        $request->headers->set('X-Health-Token', 'secret');

        $this->assertFalse($policy->allowsDetails($request));
    }
}
