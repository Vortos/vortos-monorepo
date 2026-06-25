<?php

declare(strict_types=1);

namespace Vortos\Secrets\Tests\Unit\Rotation;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Vortos\Secrets\Rotation\RotationResult;
use Vortos\Secrets\Value\SecretVersion;
use Vortos\Secrets\Value\SecretVersionState;

final class RotationResultTest extends TestCase
{
    public function test_valid_versions_includes_previous_within_grace(): void
    {
        $previous = new SecretVersion('v1', new DateTimeImmutable('2026-01-01T00:00:00+00:00'), SecretVersionState::WithinGrace);
        $new = new SecretVersion('v2', new DateTimeImmutable('2026-01-01T01:00:00+00:00'), SecretVersionState::Active);
        $graceExpiresAt = new DateTimeImmutable('2026-01-01T02:00:00+00:00');

        $result = new RotationResult($new, $previous, $graceExpiresAt);

        $midGrace = new DateTimeImmutable('2026-01-01T01:30:00+00:00');
        $validIds = array_map(static fn (SecretVersion $v): string => $v->versionId, $result->validVersions($midGrace));

        self::assertSame(['v2', 'v1'], $validIds);
    }

    public function test_valid_versions_excludes_previous_after_grace_expires(): void
    {
        $previous = new SecretVersion('v1', new DateTimeImmutable('2026-01-01T00:00:00+00:00'), SecretVersionState::Retired);
        $new = new SecretVersion('v2', new DateTimeImmutable('2026-01-01T01:00:00+00:00'), SecretVersionState::Active);
        $graceExpiresAt = new DateTimeImmutable('2026-01-01T02:00:00+00:00');

        $result = new RotationResult($new, $previous, $graceExpiresAt);

        $afterGrace = new DateTimeImmutable('2026-01-01T02:00:01+00:00');
        $validIds = array_map(static fn (SecretVersion $v): string => $v->versionId, $result->validVersions($afterGrace));

        self::assertSame(['v2'], $validIds);
    }

    public function test_valid_versions_with_no_previous(): void
    {
        $new = new SecretVersion('v1', new DateTimeImmutable(), SecretVersionState::Active);
        $result = new RotationResult($new, null, null);

        $validIds = array_map(static fn (SecretVersion $v): string => $v->versionId, $result->validVersions(new DateTimeImmutable()));

        self::assertSame(['v1'], $validIds);
    }

    public function test_is_previous_still_valid(): void
    {
        $previous = new SecretVersion('v1', new DateTimeImmutable('2026-01-01T00:00:00+00:00'), SecretVersionState::WithinGrace);
        $new = new SecretVersion('v2', new DateTimeImmutable('2026-01-01T01:00:00+00:00'), SecretVersionState::Active);
        $graceExpiresAt = new DateTimeImmutable('2026-01-01T02:00:00+00:00');
        $result = new RotationResult($new, $previous, $graceExpiresAt);

        self::assertTrue($result->isPreviousStillValid(new DateTimeImmutable('2026-01-01T01:30:00+00:00')));
        self::assertFalse($result->isPreviousStillValid(new DateTimeImmutable('2026-01-01T02:00:01+00:00')));
    }

    public function test_is_previous_still_valid_with_no_previous(): void
    {
        $new = new SecretVersion('v1', new DateTimeImmutable(), SecretVersionState::Active);
        $result = new RotationResult($new, null, null);

        self::assertFalse($result->isPreviousStillValid(new DateTimeImmutable()));
    }
}
