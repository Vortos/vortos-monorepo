<?php

declare(strict_types=1);

namespace Vortos\Migration\Tests\Schema;

use PHPUnit\Framework\TestCase;
use Vortos\Migration\Schema\MigrationPhase;

final class MigrationPhaseTest extends TestCase
{
    public function test_expand_is_not_destructive(): void
    {
        self::assertFalse(MigrationPhase::Expand->isDestructive());
    }

    public function test_contract_is_destructive(): void
    {
        self::assertTrue(MigrationPhase::Contract->isDestructive());
    }

    public function test_safe_default_is_expand(): void
    {
        self::assertSame(MigrationPhase::Expand, MigrationPhase::safeDefault());
    }

    public function test_expand_value(): void
    {
        self::assertSame('expand', MigrationPhase::Expand->value);
    }

    public function test_contract_value(): void
    {
        self::assertSame('contract', MigrationPhase::Contract->value);
    }

    public function test_from_string(): void
    {
        self::assertSame(MigrationPhase::Expand, MigrationPhase::from('expand'));
        self::assertSame(MigrationPhase::Contract, MigrationPhase::from('contract'));
    }
}
