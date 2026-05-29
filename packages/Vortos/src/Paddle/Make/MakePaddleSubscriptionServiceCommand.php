<?php

declare(strict_types=1);

namespace Vortos\Paddle\Make;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Vortos\Make\Engine\GeneratorEngine;

#[AsCommand(
    name: 'vortos:make:paddle:subscription-service',
    description: 'Generate a Paddle subscription service wrapper stub',
)]
final class MakePaddleSubscriptionServiceCommand extends Command
{
    public function __construct(private readonly GeneratorEngine $engine)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('name', InputArgument::REQUIRED, 'Service class name (e.g. BillingSubscriptionService)')
            ->addOption('context', 'c', InputOption::VALUE_REQUIRED, 'Domain context folder (e.g. Billing)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $name    = (string) $input->getArgument('name');
        $context = (string) $input->getOption('context');

        if ($context === '') {
            $output->writeln('<error>--context is required. Example: --context=Billing</error>');
            return Command::FAILURE;
        }

        $vars = [
            'Namespace' => "App\\{$context}\\Infrastructure\\Paddle",
            'ClassName' => $name,
        ];

        $output->writeln("<info>vortos:make:paddle:subscription-service</info> {$name} --context={$context}");
        $output->writeln('');

        $this->engine->write(
            "{$context}/Infrastructure/Paddle/{$name}.php",
            $this->engine->render('paddle-subscription-service', $vars),
            $output,
        );

        return Command::SUCCESS;
    }
}
