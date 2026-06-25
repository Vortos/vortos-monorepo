<?php

declare(strict_types=1);

namespace Vortos\Secrets\Tests\Unit\Console;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\DependencyInjection\ServiceLocator;
use Vortos\Secrets\Console\SecretsSetCommand;
use Vortos\Secrets\Provider\SecretsProviderRegistry;
use Vortos\Secrets\Tests\Fixtures\InMemorySecretsProvider;
use Vortos\Secrets\Value\SecretKey;

final class SecretsSetCommandTest extends TestCase
{
    public function test_sets_via_hidden_prompt_and_never_prints_the_value(): void
    {
        $provider = new InMemorySecretsProvider();
        $tester = $this->buildTester($provider);
        $tester->setInputs(['s3cr3t-prompt-value']);

        $tester->execute(['key' => 'api-token']);

        self::assertSame(0, $tester->getStatusCode());
        self::assertStringNotContainsString('s3cr3t-prompt-value', $tester->getDisplay());
        self::assertSame('s3cr3t-prompt-value', $provider->get(SecretKey::fromString('api-token'))->reveal());
    }

    public function test_sets_via_stdin_and_never_prints_the_value(): void
    {
        $provider = new InMemorySecretsProvider();
        $stream = fopen('php://memory', 'r+');
        fwrite($stream, "s3cr3t-stdin-value\n");
        rewind($stream);

        $registry = new SecretsProviderRegistry(new ServiceLocator([
            'env' => static fn (): InMemorySecretsProvider => $provider,
        ]));
        $tester = new CommandTester(new SecretsSetCommand($registry, $stream));

        $tester->execute(['key' => 'api-token', '--stdin' => true]);

        self::assertSame(0, $tester->getStatusCode());
        self::assertStringNotContainsString('s3cr3t-stdin-value', $tester->getDisplay());
        self::assertSame('s3cr3t-stdin-value', $provider->get(SecretKey::fromString('api-token'))->reveal());
    }

    public function test_empty_value_fails_closed(): void
    {
        $provider = new InMemorySecretsProvider();
        $stream = fopen('php://memory', 'r+');
        fwrite($stream, "\n");
        rewind($stream);

        $registry = new SecretsProviderRegistry(new ServiceLocator([
            'env' => static fn (): InMemorySecretsProvider => $provider,
        ]));
        $tester = new CommandTester(new SecretsSetCommand($registry, $stream));

        $tester->execute(['key' => 'api-token', '--stdin' => true]);

        self::assertSame(1, $tester->getStatusCode());
        self::assertStringContainsString('must not be empty', $tester->getDisplay());
    }

    public function test_command_definition_has_no_value_argument_or_option(): void
    {
        $definition = $this->buildCommand(new InMemorySecretsProvider())->getDefinition();

        self::assertFalse($definition->hasArgument('value'));
        self::assertFalse($definition->hasOption('value'));
    }

    private function buildTester(InMemorySecretsProvider $provider): CommandTester
    {
        return new CommandTester($this->buildCommand($provider));
    }

    private function buildCommand(InMemorySecretsProvider $provider): SecretsSetCommand
    {
        $registry = new SecretsProviderRegistry(new ServiceLocator([
            'env' => static fn (): InMemorySecretsProvider => $provider,
        ]));

        return new SecretsSetCommand($registry);
    }
}
