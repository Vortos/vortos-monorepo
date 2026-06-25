<?php

declare(strict_types=1);

namespace Vortos\Secrets\Console;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Vortos\Secrets\Preflight\RequiredSecrets;
use Vortos\Secrets\Provider\SecretsProviderRegistry;
use Vortos\Secrets\Service\SecretsPreflight;

/**
 * The CI/doctor gate: fails closed (non-zero exit) when any required secret is
 * missing, and always names every gap.
 */
#[AsCommand(
    name: 'secrets:preflight',
    description: 'Verifies every required secret is present for an environment (fail-closed CI/doctor gate).',
)]
final class SecretsPreflightCommand extends Command
{
    public function __construct(
        private readonly SecretsProviderRegistry $providers,
        private readonly SecretsPreflight $preflight,
        private readonly RequiredSecrets $requiredSecrets,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('env', null, InputOption::VALUE_REQUIRED, 'Provider driver key for the target environment', 'env')
            ->addOption('json', null, InputOption::VALUE_NONE, 'Output the report as JSON');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $provider = $this->providers->provider((string) $input->getOption('env'));
        $report = $this->preflight->check($provider, $this->requiredSecrets);

        if ($input->getOption('json')) {
            $output->writeln((string) json_encode($report->toArray(), JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        } else {
            $output->writeln($report->isSatisfied() ? "<info>{$report->explain()}</info>" : "<error>{$report->explain()}</error>");
        }

        return $report->isSatisfied() ? self::SUCCESS : self::FAILURE;
    }
}
