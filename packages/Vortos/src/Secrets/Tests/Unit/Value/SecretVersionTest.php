<?php

declare(strict_types=1);

namespace Vortos\Secrets\Tests\Unit\Value;

use DateTimeImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Vortos\Secrets\Value\SecretVersion;
use Vortos\Secrets\Value\SecretVersionState;

final class SecretVersionTest extends TestCase
{
    public function test_empty_version_id_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new SecretVersion('', new DateTimeImmutable(), SecretVersionState::Active);
    }

    public function test_with_state_returns_new_instance_with_changed_state(): void
    {
        $createdAt = new DateTimeImmutable('2026-01-01T00:00:00+00:00');
        $version = new SecretVersion('v1', $createdAt, SecretVersionState::Active);

        $retired = $version->withState(SecretVersionState::Retired);

        self::assertSame(SecretVersionState::Active, $version->state);
        self::assertSame(SecretVersionState::Retired, $retired->state);
        self::assertSame('v1', $retired->versionId);
        self::assertEquals($createdAt, $retired->createdAt);
    }

    public function test_is_valid_reflects_state(): void
    {
        $active = new SecretVersion('v1', new DateTimeImmutable(), SecretVersionState::Active);
        $retired = new SecretVersion('v2', new DateTimeImmutable(), SecretVersionState::Retired);

        self::assertTrue($active->isValid());
        self::assertFalse($retired->isValid());
    }

    public function test_array_round_trip(): void
    {
        $createdAt = new DateTimeImmutable('2026-06-23T12:00:00+00:00');
        $version = new SecretVersion('v1', $createdAt, SecretVersionState::WithinGrace);

        $restored = SecretVersion::fromArray($version->toArray());

        self::assertSame($version->versionId, $restored->versionId);
        self::assertSame($version->state, $restored->state);
        self::assertSame(
            $version->createdAt->format(DateTimeImmutable::ATOM),
            $restored->createdAt->format(DateTimeImmutable::ATOM),
        );
    }
}
