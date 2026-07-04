<?php

declare(strict_types=1);

namespace Vortos\Secrets\Tests\Unit\Console;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\DependencyInjection\ServiceLocator;
use Vortos\Secrets\Console\SecretsPreflightCommand;
use Vortos\Secrets\Preflight\RequiredSecrets;
use Vortos\Secrets\Preflight\SecretReference;
use Vortos\Secrets\Provider\EnvironmentProviderResolver;
use Vortos\Secrets\Provider\SecretsProviderRegistry;
use Vortos\Secrets\Service\SecretsPreflight;
use Vortos\Secrets\Tests\Fixtures\InMemorySecretsProvider;
use Vortos\Secrets\Value\SecretKey;
use Vortos\Secrets\Value\SecretValue;

final class SecretsPreflightCommandTest extends TestCase
{
    public function test_passes_and_exits_zero_when_all_present(): void
    {
        $provider = new InMemorySecretsProvider();
        $provider->put(SecretKey::fromString('database-password'), SecretValue::fromString('v'));

        $required = new RequiredSecrets([new SecretReference(SecretKey::fromString('database-password'))]);
        $tester = $this->buildTester($provider, $required);

        $tester->execute([]);

        self::assertSame(0, $tester->getStatusCode());
        self::assertStringContainsString('All required secrets are present.', $tester->getDisplay());
    }

    public function test_fails_closed_and_names_every_missing_secret(): void
    {
        $provider = new InMemorySecretsProvider();
        $required = new RequiredSecrets([
            new SecretReference(SecretKey::fromString('missing-one')),
            new SecretReference(SecretKey::fromString('missing-two')),
        ]);
        $tester = $this->buildTester($provider, $required);

        $tester->execute([]);

        self::assertSame(1, $tester->getStatusCode());
        self::assertStringContainsString('missing-one', $tester->getDisplay());
        self::assertStringContainsString('missing-two', $tester->getDisplay());
    }

    public function test_json_output(): void
    {
        $provider = new InMemorySecretsProvider();
        $provider->put(SecretKey::fromString('present'), SecretValue::fromString('v'));
        $required = new RequiredSecrets([new SecretReference(SecretKey::fromString('present'))]);
        $tester = $this->buildTester($provider, $required);

        $tester->execute(['--json' => true]);

        $decoded = json_decode($tester->getDisplay(), true);
        self::assertIsArray($decoded);
        self::assertTrue($decoded['satisfied']);
    }

    public function test_env_production_resolves_to_the_env_driver_and_does_not_throw(): void
    {
        // B4: the documented production gate must work out of the box — `--env=production` resolves
        // to the `env` driver instead of looking up a nonexistent driver named "production".
        $provider = new InMemorySecretsProvider();
        $provider->put(SecretKey::fromString('present'), SecretValue::fromString('v'));
        $required = new RequiredSecrets([new SecretReference(SecretKey::fromString('present'))]);
        $tester = $this->buildTester($provider, $required);

        $tester->execute(['--env' => 'production']);

        self::assertSame(0, $tester->getStatusCode());
    }

    public function test_unknown_environment_falls_back_to_default_driver(): void
    {
        $provider = new InMemorySecretsProvider();
        $provider->put(SecretKey::fromString('present'), SecretValue::fromString('v'));
        $required = new RequiredSecrets([new SecretReference(SecretKey::fromString('present'))]);
        $tester = $this->buildTester($provider, $required);

        $tester->execute(['--env' => 'some-unmapped-env']);

        self::assertSame(0, $tester->getStatusCode());
    }

    public function test_explicit_unknown_driver_override_raises(): void
    {
        $provider = new InMemorySecretsProvider();
        $tester = $this->buildTester($provider, new RequiredSecrets([]));

        $this->expectException(\Throwable::class);
        $tester->execute(['--driver' => 'nope']);
    }

    private function buildTester(InMemorySecretsProvider $provider, RequiredSecrets $required): CommandTester
    {
        $registry = new SecretsProviderRegistry(new ServiceLocator([
            'env' => static fn (): InMemorySecretsProvider => $provider,
        ]));

        return new CommandTester(new SecretsPreflightCommand(
            $registry,
            new SecretsPreflight(),
            $required,
            new EnvironmentProviderResolver(),
        ));
    }
}
