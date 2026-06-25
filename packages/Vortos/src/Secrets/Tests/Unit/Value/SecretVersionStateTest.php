<?php

declare(strict_types=1);

namespace Vortos\Secrets\Tests\Unit\Value;

use PHPUnit\Framework\TestCase;
use Vortos\Secrets\Value\SecretVersionState;

final class SecretVersionStateTest extends TestCase
{
    public function test_active_is_valid(): void
    {
        self::assertTrue(SecretVersionState::Active->isValid());
    }

    public function test_within_grace_is_valid(): void
    {
        self::assertTrue(SecretVersionState::WithinGrace->isValid());
    }

    public function test_retired_is_not_valid(): void
    {
        self::assertFalse(SecretVersionState::Retired->isValid());
    }

    public function test_revoked_is_not_valid(): void
    {
        self::assertFalse(SecretVersionState::Revoked->isValid());
    }
}
