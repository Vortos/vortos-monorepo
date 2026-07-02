<?php

declare(strict_types=1);

namespace Vortos\Secrets\DependencyInjection;

use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\Extension;
use Symfony\Component\DependencyInjection\Reference;
use Vortos\Secrets\Console\SecretsListCommand;
use Vortos\Secrets\Console\SecretsPreflightCommand;
use Vortos\Secrets\Console\SecretsRotateCommand;
use Vortos\Secrets\Console\SecretsSetCommand;
use Vortos\Secrets\Crypto\EnvelopeCipher;
use Vortos\Secrets\DependencyInjection\Compiler\CollectKeyProvidersPass;
use Vortos\Secrets\DependencyInjection\Compiler\CollectSecretsProvidersPass;
use Vortos\Secrets\Driver\Age\AgeKeyProvider;
use Vortos\Secrets\Driver\Env\EnvSecretsProvider;
use Vortos\Secrets\Driver\File\FileSecretStore;
use Vortos\Secrets\Key\KeyProviderInterface;
use Vortos\Secrets\Key\KeyProviderRegistry;
use Vortos\Secrets\Preflight\RequiredSecrets;
use Vortos\Secrets\Provider\SecretsProviderInterface;
use Vortos\Secrets\Provider\SecretsProviderRegistry;
use Vortos\Secrets\Service\RotationManager;
use Vortos\Secrets\Service\SecretInjectionPlanner;
use Vortos\Secrets\Service\SecretsPreflight;
use Vortos\Secrets\Store\SecretStoreInterface;

final class SecretsExtension extends Extension
{
    public function getAlias(): string
    {
        return 'vortos_secrets';
    }

    public function load(array $configs, ContainerBuilder $container): void
    {
        $projectDir = $container->hasParameter('kernel.project_dir')
            ? (string) $container->getParameter('kernel.project_dir')
            : '%kernel.project_dir%';

        // ── Provider + key-provider locators/registries ──

        $container->register(CollectSecretsProvidersPass::LOCATOR_ID)
            ->addTag('container.service_locator')
            ->setArgument(0, []);

        $container->register(SecretsProviderRegistry::class, SecretsProviderRegistry::class)
            ->setArgument('$drivers', new Reference(CollectSecretsProvidersPass::LOCATOR_ID))
            ->setPublic(false);

        $container->register(CollectKeyProvidersPass::LOCATOR_ID)
            ->addTag('container.service_locator')
            ->setArgument(0, []);

        $container->register(KeyProviderRegistry::class, KeyProviderRegistry::class)
            ->setArgument('$drivers', new Reference(CollectKeyProvidersPass::LOCATOR_ID))
            ->setPublic(false);

        $container->registerForAutoconfiguration(SecretsProviderInterface::class)
            ->addTag(CollectSecretsProvidersPass::TAG);

        $container->registerForAutoconfiguration(KeyProviderInterface::class)
            ->addTag(CollectKeyProvidersPass::TAG);

        // ── Default drivers: `age` key custody, file-backed envelope store, `env` provider ──

        $container->register(EnvelopeCipher::class, EnvelopeCipher::class)
            ->setPublic(false);

        $storePath = $_ENV['VORTOS_SECRETS_STORE_PATH'] ?? ($projectDir . '/var/secrets/env.enc.json');

        $container->register(FileSecretStore::class, FileSecretStore::class)
            ->setArgument('$path', $storePath)
            ->setPublic(false);

        $container->setAlias(SecretStoreInterface::class, FileSecretStore::class)
            ->setPublic(false);

        $container->register(AgeKeyProvider::class, AgeKeyProvider::class)
            ->setArgument('$publicKeyBase64', (string) ($_ENV['VORTOS_SECRETS_AGE_PUBLIC_KEY'] ?? ''))
            ->setArgument('$identitySeedEnvVar', (string) ($_ENV['VORTOS_SECRETS_AGE_IDENTITY_ENV'] ?? 'VORTOS_SECRETS_AGE_IDENTITY'))
            ->setAutoconfigured(true)
            ->addTag(CollectKeyProvidersPass::TAG)
            ->setPublic(false);

        $container->register(EnvSecretsProvider::class, EnvSecretsProvider::class)
            ->setArgument('$store', new Reference(SecretStoreInterface::class))
            ->setArgument('$keyProvider', new Reference(AgeKeyProvider::class))
            ->setArgument('$cipher', new Reference(EnvelopeCipher::class))
            ->setAutoconfigured(true)
            ->addTag(CollectSecretsProvidersPass::TAG)
            ->setPublic(false);

        // Default provider binding so cross-package consumers (e.g. vortos-deploy's
        // SshKeyCredentialProvider) can inject SecretsProviderInterface without the app
        // wiring a driver. env-backed envelope custody is the zero-config default.
        $container->setAlias(SecretsProviderInterface::class, EnvSecretsProvider::class)
            ->setPublic(false);

        // ── Services ──

        $container->register(SecretInjectionPlanner::class, SecretInjectionPlanner::class)
            ->setPublic(false);

        $container->register(SecretsPreflight::class, SecretsPreflight::class)
            ->setPublic(false);

        $container->register(RotationManager::class, RotationManager::class)
            ->setArgument('$provider', new Reference(EnvSecretsProvider::class))
            ->setPublic(false);

        // Required secrets are declared in config/secrets.php and assembled by the factory —
        // no service-definition override required (upstream P2-3).
        $container->register(\Vortos\Secrets\Preflight\RequiredSecretsFactory::class, \Vortos\Secrets\Preflight\RequiredSecretsFactory::class)
            ->setPublic(false);

        $container->register(RequiredSecrets::class, RequiredSecrets::class)
            ->setFactory([new Reference(\Vortos\Secrets\Preflight\RequiredSecretsFactory::class), '__invoke'])
            ->setArguments([$projectDir])
            ->setPublic(false);

        // ── Console commands ──

        $container->register(SecretsPreflightCommand::class, SecretsPreflightCommand::class)
            ->setArgument('$providers', new Reference(SecretsProviderRegistry::class))
            ->setArgument('$preflight', new Reference(SecretsPreflight::class))
            ->setArgument('$requiredSecrets', new Reference(RequiredSecrets::class))
            ->addTag('console.command')
            ->setPublic(false);

        $container->register(SecretsRotateCommand::class, SecretsRotateCommand::class)
            ->setArgument('$providers', new Reference(SecretsProviderRegistry::class))
            ->addTag('console.command')
            ->setPublic(false);

        $container->register(SecretsListCommand::class, SecretsListCommand::class)
            ->setArgument('$providers', new Reference(SecretsProviderRegistry::class))
            ->addTag('console.command')
            ->setPublic(false);

        $container->register(SecretsSetCommand::class, SecretsSetCommand::class)
            ->setArgument('$providers', new Reference(SecretsProviderRegistry::class))
            ->addTag('console.command')
            ->setPublic(false);
    }
}
