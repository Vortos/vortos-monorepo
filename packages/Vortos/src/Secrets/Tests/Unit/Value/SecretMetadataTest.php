<?php

declare(strict_types=1);

namespace Vortos\Secrets\Tests\Unit\Value;

use DateTimeImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Vortos\Secrets\Value\SecretKey;
use Vortos\Secrets\Value\SecretMetadata;
use Vortos\Secrets\Value\SecretVersion;
use Vortos\Secrets\Value\SecretVersionState;

final class SecretMetadataTest extends TestCase
{
    public function test_empty_versions_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new SecretMetadata(SecretKey::fromString('k'), [], 'v1');
    }

    public function test_unknown_current_version_rejected(): void
    {
        $v1 = new SecretVersion('v1', new DateTimeImmutable(), SecretVersionState::Active);

        $this->expectException(InvalidArgumentException::class);
        new SecretMetadata(SecretKey::fromString('k'), [$v1], 'v2');
    }

    public function test_current_returns_matching_version(): void
    {
        $v1 = new SecretVersion('v1', new DateTimeImmutable(), SecretVersionState::Retired);
        $v2 = new SecretVersion('v2', new DateTimeImmutable(), SecretVersionState::Active);
        $metadata = new SecretMetadata(SecretKey::fromString('k'), [$v1, $v2], 'v2');

        self::assertSame($v2, $metadata->current());
    }

    public function test_valid_versions_excludes_retired_and_revoked(): void
    {
        $v1 = new SecretVersion('v1', new DateTimeImmutable(), SecretVersionState::Retired);
        $v2 = new SecretVersion('v2', new DateTimeImmutable(), SecretVersionState::WithinGrace);
        $v3 = new SecretVersion('v3', new DateTimeImmutable(), SecretVersionState::Active);
        $v4 = new SecretVersion('v4', new DateTimeImmutable(), SecretVersionState::Revoked);

        $metadata = new SecretMetadata(SecretKey::fromString('k'), [$v1, $v2, $v3, $v4], 'v3');

        $validIds = array_map(static fn (SecretVersion $v): string => $v->versionId, $metadata->validVersions());

        self::assertSame(['v2', 'v3'], $validIds);
    }

    public function test_to_array(): void
    {
        $v1 = new SecretVersion('v1', new DateTimeImmutable('2026-01-01T00:00:00+00:00'), SecretVersionState::Active);
        $metadata = new SecretMetadata(SecretKey::fromString('k'), [$v1], 'v1');

        $array = $metadata->toArray();

        self::assertSame('k', $array['key']);
        self::assertSame('v1', $array['currentVersionId']);
        self::assertCount(1, $array['versions']);
    }
}
