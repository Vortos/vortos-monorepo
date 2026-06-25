<?php

declare(strict_types=1);

namespace Vortos\Secrets\Tests\Unit\Service;

use PHPUnit\Framework\TestCase;
use Vortos\Secrets\Exception\SecretNotFoundException;
use Vortos\Secrets\Rotation\RotationPolicy;
use Vortos\Secrets\Service\RotationManager;
use Vortos\Secrets\Tests\Fixtures\InMemorySecretsProvider;
use Vortos\Secrets\Value\SecretKey;
use Vortos\Secrets\Value\SecretValue;
use Vortos\Secrets\Value\SecretVersionState;

final class RotationManagerTest extends TestCase
{
    public function test_rotate_if_due_skips_when_not_due(): void
    {
        $provider = new InMemorySecretsProvider();
        $key = SecretKey::fromString('fresh-secret');
        $provider->put($key, SecretValue::fromString('v1'));

        $policy = new RotationPolicy(intervalSeconds: 3600, gracePeriodSeconds: 60, maxAgeSeconds: 7200);
        $manager = new RotationManager($provider);

        $result = $manager->rotateIfDue($key, $policy, new \DateTimeImmutable());

        self::assertNull($result);
        self::assertCount(1, $provider->versions($key)->versions);
    }

    public function test_rotate_if_due_rotates_when_due(): void
    {
        $provider = new InMemorySecretsProvider();
        $key = SecretKey::fromString('stale-secret');
        $provider->put($key, SecretValue::fromString('v1'));

        $policy = new RotationPolicy(intervalSeconds: 3600, gracePeriodSeconds: 60, maxAgeSeconds: 7200);
        $manager = new RotationManager($provider);

        $farFuture = $provider->versions($key)->current()->createdAt->modify('+2 hours');
        $result = $manager->rotateIfDue($key, $policy, $farFuture);

        self::assertNotNull($result);
        self::assertSame(SecretVersionState::Active, $result->newVersion->state);
        self::assertSame(SecretVersionState::WithinGrace, $result->previousVersion->state);
    }

    public function test_force_rotate_always_rotates(): void
    {
        $provider = new InMemorySecretsProvider();
        $key = SecretKey::fromString('forced-secret');
        $provider->put($key, SecretValue::fromString('v1'));

        $policy = new RotationPolicy(intervalSeconds: 3600, gracePeriodSeconds: 60, maxAgeSeconds: 7200);
        $result = (new RotationManager($provider))->forceRotate($key, $policy);

        self::assertCount(2, $provider->versions($key)->versions);
        self::assertSame($result->newVersion->versionId, $provider->versions($key)->currentVersionId);
    }

    public function test_rotate_if_due_on_missing_key_fails_closed(): void
    {
        $provider = new InMemorySecretsProvider();
        $policy = new RotationPolicy(intervalSeconds: 3600, gracePeriodSeconds: 60, maxAgeSeconds: 7200);

        $this->expectException(SecretNotFoundException::class);
        (new RotationManager($provider))->rotateIfDue(SecretKey::fromString('nonexistent'), $policy, new \DateTimeImmutable());
    }
}
