<?php

declare(strict_types=1);

namespace Vortos\Secrets\Tests\Unit\Console;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\DependencyInjection\ServiceLocator;
use Vortos\Secrets\Console\SecretsListCommand;
use Vortos\Secrets\Provider\SecretsProviderRegistry;
use Vortos\Secrets\Tests\Fixtures\InMemorySecretsProvider;
use Vortos\Secrets\Value\SecretKey;
use Vortos\Secrets\Value\SecretValue;

final class SecretsListCommandTest extends TestCase
{
    public function test_lists_names_sorted_and_never_values(): void
    {
        $provider = new InMemorySecretsProvider();
        $provider->put(SecretKey::fromString('zebra-secret'), SecretValue::fromString('zebra-value'));
        $provider->put(SecretKey::fromString('alpha-secret'), SecretValue::fromString('alpha-value'));

        $tester = $this->buildTester($provider);
        $tester->execute([]);

        self::assertSame(0, $tester->getStatusCode());
        $lines = array_values(array_filter(explode("\n", $tester->getDisplay())));
        self::assertSame(['alpha-secret', 'zebra-secret'], $lines);
        self::assertStringNotContainsString('zebra-value', $tester->getDisplay());
        self::assertStringNotContainsString('alpha-value', $tester->getDisplay());
    }

    public function test_empty_provider_prints_nothing(): void
    {
        $tester = $this->buildTester(new InMemorySecretsProvider());
        $tester->execute([]);

        self::assertSame(0, $tester->getStatusCode());
        self::assertSame('', $tester->getDisplay());
    }

    public function test_json_output(): void
    {
        $provider = new InMemorySecretsProvider();
        $provider->put(SecretKey::fromString('one'), SecretValue::fromString('v'));

        $tester = $this->buildTester($provider);
        $tester->execute(['--json' => true]);

        self::assertSame(['one'], json_decode($tester->getDisplay(), true));
    }

    private function buildTester(InMemorySecretsProvider $provider): CommandTester
    {
        $registry = new SecretsProviderRegistry(new ServiceLocator([
            'env' => static fn (): InMemorySecretsProvider => $provider,
        ]));

        return new CommandTester(new SecretsListCommand($registry));
    }
}
