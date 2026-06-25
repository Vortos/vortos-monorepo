<?php

declare(strict_types=1);

namespace Vortos\Secrets\Tests\Unit\Console;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\DependencyInjection\ServiceLocator;
use Vortos\Secrets\Console\SecretsPreflightCommand;
use Vortos\Secrets\Preflight\RequiredSecrets;
use Vortos\Secrets\Preflight\SecretReference;
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

    public function test_unknown_env_driver_key_raises(): void
    {
        $provider = new InMemorySecretsProvider();
        $tester = $this->buildTester($provider, new RequiredSecrets([]));

        $this->expectException(\Throwable::class);
        $tester->execute(['--env' => 'nope']);
    }

    private function buildTester(InMemorySecretsProvider $provider, RequiredSecrets $required): CommandTester
    {
        $registry = new SecretsProviderRegistry(new ServiceLocator([
            'env' => static fn (): InMemorySecretsProvider => $provider,
        ]));

        return new CommandTester(new SecretsPreflightCommand($registry, new SecretsPreflight(), $required));
    }
}
