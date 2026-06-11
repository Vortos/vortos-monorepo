<?php

declare(strict_types=1);

namespace Vortos\Make\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Vortos\Make\Engine\GeneratorEngine;

#[AsCommand(
    name: 'vortos:make:infra-config',
    description: 'Generate an InfraConfig class for Terraform export (vortos:iac:export)',
)]
final class MakeInfraConfigCommand extends Command
{
    public function __construct(private readonly GeneratorEngine $engine)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('provider', null, InputOption::VALUE_REQUIRED, 'Kafka Terraform provider: confluent or kafka (Mongey, for self-hosted/MSK)', 'kafka')
            ->addOption('output', null, InputOption::VALUE_REQUIRED, 'Terraform output file', 'infra/kafka_topics.tf.json');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $provider = strtolower((string) $input->getOption('provider'));

        $providerCase = match ($provider) {
            'confluent' => 'Confluent',
            'kafka', 'mongey' => 'Kafka',
            default => null,
        };

        if ($providerCase === null) {
            $output->writeln("<error>--provider must be 'confluent' or 'kafka'.</error>");
            return Command::FAILURE;
        }

        $vars = [
            'ClassName' => 'AppInfraConfig',
            'Provider' => $providerCase,
            'OutputFile' => (string) $input->getOption('output'),
        ];

        $output->writeln('<info>vortos:make:infra-config</info> --provider=' . $provider);
        $output->writeln('');

        $this->engine->write(
            'Shared/Infrastructure/Iac/AppInfraConfig.php',
            $this->engine->render('infra-config', $vars),
            $output,
        );

        $output->writeln('');
        $output->writeln('Next: register <info>AppInfraConfig</info> in your DI config, then run <info>vortos:iac:export</info>');

        return Command::SUCCESS;
    }
}
