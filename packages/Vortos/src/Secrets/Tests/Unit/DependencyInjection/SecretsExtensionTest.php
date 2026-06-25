<?php

declare(strict_types=1);

namespace Vortos\Secrets\Tests\Unit\DependencyInjection;

use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;
use Vortos\Secrets\Console\SecretsListCommand;
use Vortos\Secrets\Console\SecretsPreflightCommand;
use Vortos\Secrets\Console\SecretsRotateCommand;
use Vortos\Secrets\Console\SecretsSetCommand;
use Vortos\Secrets\Crypto\EnvelopeCipher;
use Vortos\Secrets\DependencyInjection\Compiler\CollectKeyProvidersPass;
use Vortos\Secrets\DependencyInjection\Compiler\CollectSecretsProvidersPass;
use Vortos\Secrets\DependencyInjection\SecretsExtension;
use Vortos\Secrets\Driver\Age\AgeKeyProvider;
use Vortos\Secrets\Driver\Env\EnvSecretsProvider;
use Vortos\Secrets\Driver\File\FileSecretStore;
use Vortos\Secrets\Key\KeyProviderRegistry;
use Vortos\Secrets\Preflight\RequiredSecrets;
use Vortos\Secrets\Provider\SecretsProviderRegistry;
use Vortos\Secrets\Service\RotationManager;
use Vortos\Secrets\Service\SecretInjectionPlanner;
use Vortos\Secrets\Service\SecretsPreflight;
use Vortos\Secrets\Store\SecretStoreInterface;

final class SecretsExtensionTest extends TestCase
{
    public function test_registers_provider_and_key_provider_registries(): void
    {
        $container = $this->compiledContainer();

        self::assertSame(SecretsProviderRegistry::class, $container->getDefinition(SecretsProviderRegistry::class)->getClass());
        self::assertSame(KeyProviderRegistry::class, $container->getDefinition(KeyProviderRegistry::class)->getClass());
    }

    public function test_default_env_provider_is_collected_under_its_driver_key(): void
    {
        $container = $this->compiledContainer();

        $map = $container->getDefinition(CollectSecretsProvidersPass::LOCATOR_ID)->getArgument(0);
        self::assertArrayHasKey('env', $map);
        self::assertInstanceOf(Reference::class, $map['env']);
        self::assertSame(EnvSecretsProvider::class, (string) $map['env']);
    }

    public function test_default_age_key_provider_is_collected_under_its_driver_key(): void
    {
        $container = $this->compiledContainer();

        $map = $container->getDefinition(CollectKeyProvidersPass::LOCATOR_ID)->getArgument(0);
        self::assertArrayHasKey('age', $map);
        self::assertInstanceOf(Reference::class, $map['age']);
        self::assertSame(AgeKeyProvider::class, (string) $map['age']);
    }

    public function test_store_path_defaults_under_project_dir(): void
    {
        $container = $this->compiledContainer();

        self::assertSame(
            $container->getParameter('kernel.project_dir') . '/var/secrets/env.enc.json',
            $container->getDefinition(FileSecretStore::class)->getArgument('$path'),
        );
        self::assertSame(FileSecretStore::class, (string) $container->getAlias(SecretStoreInterface::class));
    }

    public function test_store_path_env_var_overrides_default(): void
    {
        $_ENV['VORTOS_SECRETS_STORE_PATH'] = '/custom/path/secrets.enc.json';

        try {
            $container = $this->compiledContainer();
            self::assertSame('/custom/path/secrets.enc.json', $container->getDefinition(FileSecretStore::class)->getArgument('$path'));
        } finally {
            unset($_ENV['VORTOS_SECRETS_STORE_PATH']);
        }
    }

    public function test_age_key_provider_reads_public_key_and_identity_env_var_from_env(): void
    {
        $_ENV['VORTOS_SECRETS_AGE_PUBLIC_KEY'] = 'some-base64-key';
        $_ENV['VORTOS_SECRETS_AGE_IDENTITY_ENV'] = 'CUSTOM_IDENTITY_VAR';

        try {
            $container = $this->compiledContainer();
            $def = $container->getDefinition(AgeKeyProvider::class);
            self::assertSame('some-base64-key', $def->getArgument('$publicKeyBase64'));
            self::assertSame('CUSTOM_IDENTITY_VAR', $def->getArgument('$identitySeedEnvVar'));
        } finally {
            unset($_ENV['VORTOS_SECRETS_AGE_PUBLIC_KEY'], $_ENV['VORTOS_SECRETS_AGE_IDENTITY_ENV']);
        }
    }

    public function test_env_secrets_provider_wired_to_default_store_cipher_and_key_provider(): void
    {
        $container = $this->compiledContainer();
        $def = $container->getDefinition(EnvSecretsProvider::class);

        self::assertSame(SecretStoreInterface::class, (string) $def->getArgument('$store'));
        self::assertSame(AgeKeyProvider::class, (string) $def->getArgument('$keyProvider'));
        self::assertSame(EnvelopeCipher::class, (string) $def->getArgument('$cipher'));
    }

    public function test_services_are_registered(): void
    {
        $container = $this->compiledContainer();

        self::assertTrue($container->hasDefinition(SecretInjectionPlanner::class));
        self::assertTrue($container->hasDefinition(SecretsPreflight::class));
        self::assertTrue($container->hasDefinition(RotationManager::class));
    }

    public function test_required_secrets_defaults_to_empty_list(): void
    {
        $container = $this->compiledContainer();

        self::assertSame([], $container->getDefinition(RequiredSecrets::class)->getArgument('$references'));
    }

    public function test_console_commands_are_registered_and_tagged(): void
    {
        $container = $this->compiledContainer();

        foreach ([
            SecretsPreflightCommand::class,
            SecretsRotateCommand::class,
            SecretsListCommand::class,
            SecretsSetCommand::class,
        ] as $commandClass) {
            $def = $container->getDefinition($commandClass);
            self::assertTrue($def->hasTag('console.command'), "{$commandClass} must be tagged console.command");
            self::assertFalse($def->isPublic(), "{$commandClass} must not be public");
        }
    }

    public function test_no_registered_service_is_public_except_none_expected(): void
    {
        $container = $this->compiledContainer();

        foreach ([
            SecretsProviderRegistry::class,
            KeyProviderRegistry::class,
            EnvelopeCipher::class,
            FileSecretStore::class,
            AgeKeyProvider::class,
            EnvSecretsProvider::class,
            SecretInjectionPlanner::class,
            SecretsPreflight::class,
            RotationManager::class,
            RequiredSecrets::class,
        ] as $serviceClass) {
            self::assertFalse($container->getDefinition($serviceClass)->isPublic(), "{$serviceClass} must not be public");
        }
    }

    private function compiledContainer(): ContainerBuilder
    {
        $container = new ContainerBuilder();
        $container->setParameter('kernel.project_dir', sys_get_temp_dir() . '/vortos-secrets-ext-test');

        (new SecretsExtension())->load([], $container);

        // Run the two collecting passes directly rather than a full
        // ContainerBuilder::compile() — compiling here would also run
        // RemoveUnusedDefinitionsPass, which (absent the FrameworkBundle's
        // console/public-alias-keeping passes this raw ContainerBuilder doesn't
        // have) would prune every private service nothing else references yet.
        (new CollectSecretsProvidersPass())->process($container);
        (new CollectKeyProvidersPass())->process($container);

        return $container;
    }
}
