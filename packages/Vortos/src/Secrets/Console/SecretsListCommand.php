<?php

declare(strict_types=1);

namespace Vortos\Secrets\Console;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Vortos\Secrets\Provider\SecretsProviderRegistry;
use Vortos\Secrets\Value\SecretKey;

/** Lists known secret names for an environment — names only, never values. */
#[AsCommand(
    name: 'secrets:list',
    description: 'Lists known secret names for an environment — names only, never values.',
)]
final class SecretsListCommand extends Command
{
    public function __construct(private readonly SecretsProviderRegistry $providers)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('env', null, InputOption::VALUE_REQUIRED, 'Provider driver key for the target environment', 'env')
            ->addOption('json', null, InputOption::VALUE_NONE, 'Output as JSON');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $provider = $this->providers->provider((string) $input->getOption('env'));

        $names = array_map(static fn (SecretKey $key): string => $key->value(), $provider->list());
        sort($names);

        if ($input->getOption('json')) {
            $output->writeln((string) json_encode($names, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        foreach ($names as $name) {
            $output->writeln($name);
        }

        return self::SUCCESS;
    }
}
