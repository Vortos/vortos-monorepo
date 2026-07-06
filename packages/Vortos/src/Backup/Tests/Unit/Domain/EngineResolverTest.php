<?php

declare(strict_types=1);

namespace Vortos\Backup\Tests\Unit\Domain;

use PHPUnit\Framework\TestCase;
use Vortos\Backup\Domain\DatabaseEngine;
use Vortos\Backup\Domain\EngineResolver;
use Vortos\Backup\Domain\Exception\EngineNotConfiguredException;
use Vortos\Backup\Domain\Exception\UnknownEngineException;

final class EngineResolverTest extends TestCase
{
    public function test_flag_wins_over_configured_default(): void
    {
        $resolver = new EngineResolver('postgres');

        $this->assertSame(DatabaseEngine::Mongo, $resolver->resolve('mongo'));
    }

    public function test_configured_default_used_when_no_flag(): void
    {
        $resolver = new EngineResolver('postgres');

        $this->assertSame(DatabaseEngine::Postgres, $resolver->resolve(null));
    }

    public function test_empty_or_whitespace_flag_is_treated_as_absent(): void
    {
        $resolver = new EngineResolver('mongo');

        $this->assertSame(DatabaseEngine::Mongo, $resolver->resolve(''));
        $this->assertSame(DatabaseEngine::Mongo, $resolver->resolve('   '));
    }

    public function test_whitespace_is_trimmed_from_values(): void
    {
        $resolver = new EngineResolver('  postgres  ');

        $this->assertSame(DatabaseEngine::Postgres, $resolver->resolve(null));
        $this->assertSame('postgres', $resolver->configuredDefault());
    }

    public function test_fails_closed_when_neither_flag_nor_default_present(): void
    {
        $resolver = new EngineResolver(null);

        $this->expectException(EngineNotConfiguredException::class);
        $this->expectExceptionMessage('VORTOS_BACKUP_ENGINE');
        $resolver->resolve(null);
    }

    public function test_blank_configured_default_is_normalised_to_null(): void
    {
        $resolver = new EngineResolver('   ');

        $this->assertNull($resolver->configuredDefault());
        $this->expectException(EngineNotConfiguredException::class);
        $resolver->resolve(null);
    }

    public function test_unknown_engine_value_fails_closed(): void
    {
        $resolver = new EngineResolver(null);

        $this->expectException(UnknownEngineException::class);
        $resolver->resolve('cassandra');
    }
}
