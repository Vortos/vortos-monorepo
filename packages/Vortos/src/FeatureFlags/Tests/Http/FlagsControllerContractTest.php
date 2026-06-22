<?php

declare(strict_types=1);

namespace Vortos\FeatureFlags\Tests\Http;

use PHPUnit\Framework\TestCase;
use Vortos\FeatureFlags\FeatureFlag;
use Vortos\FeatureFlags\FlagContext;
use Vortos\FeatureFlags\FlagEvaluator;
use Vortos\FeatureFlags\FlagRegistry;
use Vortos\FeatureFlags\FlagValue;
use Vortos\FeatureFlags\FlagValueType;
use Vortos\FeatureFlags\Http\FlagContextResolverInterface;
use Vortos\FeatureFlags\Http\FlagsController;
use Vortos\FeatureFlags\Storage\FlagStorageInterface;
use Vortos\Http\Request;

/**
 * Asserts GET /api/flags emits exactly the JSON shape the @vortos/flags SDK consumes
 * (FlagResponse — see WIRE_CONTRACT.md). This test is the cross-repo guardrail: CI
 * cannot see the SDK, so the contract is pinned here.
 */
final class FlagsControllerContractTest extends TestCase
{
    public function test_response_matches_flag_response_contract(): void
    {
        $now  = new \DateTimeImmutable('2024-01-01');
        $bool = new FeatureFlag('1', 'dark-mode', '', true, [], null, $now, $now);
        $json = new FeatureFlag(
            '2', 'pricing-config', '', true, [], null, $now, $now,
            FlagValueType::Json, FlagValue::json(null), ['tier' => 'pro'],
        );

        $storage = $this->createMock(FlagStorageInterface::class);
        $storage->method('findAll')->willReturn([$bool, $json]);

        $resolver = $this->createMock(FlagContextResolverInterface::class);
        $resolver->method('resolve')->willReturn(new FlagContext('u1'));

        $controller = new FlagsController(new FlagRegistry($storage, new FlagEvaluator()), $resolver);

        $response = $controller(new Request());
        $body     = json_decode($response->getContent(), true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame(200, $response->getStatusCode());

        // FlagResponse: flags / variants / payloads / version — and nothing else.
        $this->assertSame(['flags', 'variants', 'payloads', 'version'], array_keys($body));
        $this->assertContains('dark-mode', $body['flags']);
        $this->assertContains('pricing-config', $body['flags']);
        $this->assertSame(['pricing-config' => ['tier' => 'pro']], $body['payloads']);
        $this->assertIsString($body['version']);

        // No ruleset / internal fields leak to the client.
        $this->assertArrayNotHasKey('rules', $body);
        $this->assertArrayNotHasKey('users', $body);
    }
}
