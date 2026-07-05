<?php

declare(strict_types=1);

namespace Vortos\Deploy\Console;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Vortos\Deploy\Definition\LayeredDefinitionResolver;
use Vortos\Deploy\Runtime\FileSecret;
use Vortos\Deploy\Runtime\FileSecretDecryptor;
use Vortos\Deploy\Runtime\FileSecretMaterializer;
use Vortos\Secrets\Provider\SecretsProviderRegistry;

/**
 * Materialises the deployment's declared file-shaped secrets (G8) from the age store to their tmpfs
 * host paths, so the cutover compose can bind-mount them read-only into the color.
 *
 * Runs on the target inside the deploy one-shot (which holds the age identity + store), before the
 * cutover deploy. Plaintext is written only to tmpfs (RAM); the revealed SecretValues are wiped
 * immediately after use. Fail-closed: a missing declared secret aborts the deploy.
 */
#[AsCommand(
    name: 'vortos:deploy:materialize-file-secrets',
    description: 'Decrypt declared file-shaped secrets to their tmpfs paths for the cutover mounts (G8).',
)]
final class MaterializeFileSecretsCommand extends Command
{
    public function __construct(
        private readonly LayeredDefinitionResolver $resolver,
        private readonly SecretsProviderRegistry $providers,
        private readonly FileSecretDecryptor $decryptor = new FileSecretDecryptor(),
        private readonly FileSecretMaterializer $materializer = new FileSecretMaterializer(),
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('env', null, InputOption::VALUE_REQUIRED, 'Target environment name', 'production')
            ->addOption('secrets-driver', null, InputOption::VALUE_REQUIRED, 'Secrets provider driver key', 'age')
            ->addOption('json', null, InputOption::VALUE_NONE, 'Machine-readable output');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $env = (string) $input->getOption('env');
        $json = (bool) $input->getOption('json');

        $fileSecrets = $this->resolver->resolve($env)->runtimeService->fileSecrets;

        if ($fileSecrets === []) {
            if ($json) {
                $output->writeln((string) json_encode(['materialized' => []], \JSON_THROW_ON_ERROR));
            } else {
                $output->writeln('<info>No file-shaped secrets declared — nothing to materialize.</info>');
            }

            return self::SUCCESS;
        }

        $provider = $this->providers->provider((string) $input->getOption('secrets-driver'));

        try {
            $satisfied = $this->materializer->materialize(
                $fileSecrets,
                fn (FileSecret $fileSecret): string => $this->decryptor->plaintext($fileSecret, $provider),
            );
        } catch (\Throwable $e) {
            // Never leave a half-written tmpfs tree on failure.
            $this->materializer->wipe();

            $output->writeln(sprintf('<error>Failed to materialize file secrets: %s</error>', $e->getMessage()));

            return self::FAILURE;
        }

        if ($json) {
            $output->writeln((string) json_encode(['materialized' => $satisfied], \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES));
        } else {
            $output->writeln(sprintf('<info>✔ Materialized %d file secret(s) to tmpfs.</info>', count($satisfied)));
        }

        return self::SUCCESS;
    }
}
