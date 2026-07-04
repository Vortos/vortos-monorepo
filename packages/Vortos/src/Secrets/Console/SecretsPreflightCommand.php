<?php

declare(strict_types=1);

namespace Vortos\Secrets\Console;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Vortos\Secrets\Preflight\RequiredSecrets;
use Vortos\Secrets\Provider\EnvironmentProviderResolver;
use Vortos\Secrets\Provider\SecretsProviderRegistry;
use Vortos\Secrets\Service\SecretsPreflight;

/**
 * The CI/doctor gate: fails closed (non-zero exit) when any required secret is
 * missing, and always names every gap.
 *
 * `--env` is the **environment name** (production/staging/…), resolved to a secrets driver via
 * {@see EnvironmentProviderResolver} — so `--env=production` works out of the box against the
 * zero-config `env` driver instead of throwing UnknownDriverException (B4). `--driver` overrides the
 * resolution for apps running more than one custody backend.
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
        private readonly EnvironmentProviderResolver $environmentResolver,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('env', null, InputOption::VALUE_REQUIRED, 'Target environment name (production, staging, …)', 'production')
            ->addOption('driver', null, InputOption::VALUE_REQUIRED, 'Override the secrets driver key (bypasses environment resolution)')
            ->addOption('json', null, InputOption::VALUE_NONE, 'Output the report as JSON');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $env = (string) $input->getOption('env');
        $driverOption = $input->getOption('driver');
        $driverKey = is_string($driverOption) && $driverOption !== ''
            ? $driverOption
            : $this->environmentResolver->driverFor($env);

        $provider = $this->providers->provider($driverKey);
        $report = $this->preflight->check($provider, $this->requiredSecrets);

        if ($input->getOption('json')) {
            $output->writeln((string) json_encode($report->toArray(), JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        } else {
            $output->writeln($report->isSatisfied() ? "<info>{$report->explain()}</info>" : "<error>{$report->explain()}</error>");
        }

        return $report->isSatisfied() ? self::SUCCESS : self::FAILURE;
    }
}
