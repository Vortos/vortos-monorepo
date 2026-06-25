<?php

declare(strict_types=1);

namespace Vortos\Secrets\Console;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Vortos\Secrets\Exception\SecretsException;
use Vortos\Secrets\Provider\SecretsProviderRegistry;
use Vortos\Secrets\Rotation\RotationPolicy;
use Vortos\Secrets\Value\SecretKey;

/**
 * Two-phase rotation, driven explicitly by an operator. Prints only version ids
 * and the grace-expiry timestamp — never a secret value.
 */
#[AsCommand(
    name: 'secrets:rotate',
    description: 'Rotates a secret, keeping the previous version valid during the grace window.',
)]
final class SecretsRotateCommand extends Command
{
    public function __construct(private readonly SecretsProviderRegistry $providers)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('key', InputArgument::REQUIRED, 'Secret key to rotate')
            ->addOption('env', null, InputOption::VALUE_REQUIRED, 'Provider driver key for the target environment', 'env')
            ->addOption('interval', null, InputOption::VALUE_REQUIRED, 'Rotation interval in seconds', '2592000')
            ->addOption('grace', null, InputOption::VALUE_REQUIRED, 'Grace period in seconds', '86400')
            ->addOption('max-age', null, InputOption::VALUE_REQUIRED, 'Max age in seconds', '5184000');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $provider = $this->providers->provider((string) $input->getOption('env'));
        $key = SecretKey::fromString((string) $input->getArgument('key'));
        $policy = new RotationPolicy(
            (int) $input->getOption('interval'),
            (int) $input->getOption('grace'),
            (int) $input->getOption('max-age'),
        );

        try {
            $result = $provider->rotate($key, $policy);
        } catch (SecretsException $e) {
            $output->writeln(sprintf('<error>%s</error>', $e->getMessage()));

            return self::FAILURE;
        }

        $output->writeln(sprintf(
            '<info>Rotated %s: new version %s. Previous version %s valid until %s.</info>',
            $key->value(),
            $result->newVersion->versionId,
            $result->previousVersion !== null ? $result->previousVersion->versionId : 'n/a',
            $result->graceExpiresAt !== null ? $result->graceExpiresAt->format(DATE_ATOM) : 'n/a',
        ));

        return self::SUCCESS;
    }
}
