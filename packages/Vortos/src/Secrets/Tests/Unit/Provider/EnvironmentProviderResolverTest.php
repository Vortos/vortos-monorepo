<?php

declare(strict_types=1);

namespace Vortos\Secrets\Tests\Unit\Provider;

use PHPUnit\Framework\TestCase;
use Vortos\Secrets\Provider\EnvironmentProviderResolver;

/**
 * B4: environment → driver resolution. Defaults every environment to the `env` driver; honours an
 * explicit per-environment map; parses the `env:driver,…` spec.
 */
final class EnvironmentProviderResolverTest extends TestCase
{
    public function test_defaults_every_environment_to_the_env_driver(): void
    {
        $resolver = new EnvironmentProviderResolver();

        self::assertSame('env', $resolver->driverFor('production'));
        self::assertSame('env', $resolver->driverFor('staging'));
        self::assertFalse($resolver->hasExplicitMapping('production'));
    }

    public function test_explicit_mapping_wins(): void
    {
        $resolver = new EnvironmentProviderResolver(['production' => 'vault'], 'env');

        self::assertSame('vault', $resolver->driverFor('production'));
        self::assertTrue($resolver->hasExplicitMapping('production'));
        self::assertSame('env', $resolver->driverFor('staging'));
    }

    public function test_from_spec_parses_pairs(): void
    {
        $resolver = EnvironmentProviderResolver::fromSpec(' production:env , staging:vault ', 'env');

        self::assertSame('env', $resolver->driverFor('production'));
        self::assertSame('vault', $resolver->driverFor('staging'));
        self::assertSame('env', $resolver->driverFor('dev'));
    }

    public function test_from_spec_ignores_malformed_pairs(): void
    {
        $resolver = EnvironmentProviderResolver::fromSpec('garbage,,production:', 'env');

        self::assertSame('env', $resolver->driverFor('production'));
        self::assertFalse($resolver->hasExplicitMapping('production'));
    }
}
