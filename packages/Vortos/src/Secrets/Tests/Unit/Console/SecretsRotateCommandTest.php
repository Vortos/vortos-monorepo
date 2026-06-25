<?php

declare(strict_types=1);

namespace Vortos\Secrets\Tests\Unit\Console;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\DependencyInjection\ServiceLocator;
use Vortos\Secrets\Console\SecretsRotateCommand;
use Vortos\Secrets\Provider\SecretsProviderRegistry;
use Vortos\Secrets\Tests\Fixtures\InMemorySecretsProvider;
use Vortos\Secrets\Value\SecretKey;
use Vortos\Secrets\Value\SecretValue;

final class SecretsRotateCommandTest extends TestCase
{
    public function test_rotates_and_reports_versions_without_revealing_value(): void
    {
        $provider = new InMemorySecretsProvider();
        $provider->put(SecretKey::fromString('api-token'), SecretValue::fromString('original-value'));

        $tester = $this->buildTester($provider);
        $tester->execute(['key' => 'api-token']);

        self::assertSame(0, $tester->getStatusCode());
        $output = $tester->getDisplay();
        self::assertStringContainsString('Rotated api-token', $output);
        self::assertStringNotContainsString('original-value', $output);
    }

    public function test_rotate_of_missing_key_fails_closed(): void
    {
        $provider = new InMemorySecretsProvider();
        $tester = $this->buildTester($provider);

        $tester->execute(['key' => 'nonexistent']);

        self::assertSame(1, $tester->getStatusCode());
        self::assertStringContainsString('not found', $tester->getDisplay());
    }

    public function test_custom_policy_options_are_honored(): void
    {
        $provider = new InMemorySecretsProvider();
        $provider->put(SecretKey::fromString('api-token'), SecretValue::fromString('v'));

        $tester = $this->buildTester($provider);
        $tester->execute([
            'key' => 'api-token',
            '--interval' => '60',
            '--grace' => '30',
            '--max-age' => '120',
        ]);

        self::assertSame(0, $tester->getStatusCode());
    }

    private function buildTester(InMemorySecretsProvider $provider): CommandTester
    {
        $registry = new SecretsProviderRegistry(new ServiceLocator([
            'env' => static fn (): InMemorySecretsProvider => $provider,
        ]));

        return new CommandTester(new SecretsRotateCommand($registry));
    }
}
