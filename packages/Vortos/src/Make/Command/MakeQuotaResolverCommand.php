<?php

declare(strict_types=1);

namespace Vortos\Make\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Vortos\Make\Engine\GeneratorEngine;

#[AsCommand(
    name: 'vortos:make:quota-resolver',
    description: 'Generate a quota subject resolver',
)]
final class MakeQuotaResolverCommand extends Command
{
    public function __construct(private readonly GeneratorEngine $engine)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('name', InputArgument::REQUIRED, 'Resolver name without "QuotaResolver" suffix (e.g. Organization)')
            ->addOption('context', 'c', InputOption::VALUE_REQUIRED, 'Domain context folder (e.g. Billing)')
            ->addOption('bucket', 'b', InputOption::VALUE_REQUIRED, 'Low-cardinality bucket name (e.g. organization)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $name = (string) $input->getArgument('name');
        $context = (string) $input->getOption('context');
        $bucket = (string) ($input->getOption('bucket') ?: strtolower(preg_replace('/(?<!^)[A-Z]/', '_$0', $name) ?? $name));

        if ($context === '') {
            $output->writeln('<error>--context is required. Example: --context=Billing</error>');
            return Command::FAILURE;
        }

        if (!preg_match('/^[a-z0-9._-]+$/', $bucket)) {
            $output->writeln('<error>--bucket must contain only lowercase letters, numbers, dots, underscores, or hyphens.</error>');
            return Command::FAILURE;
        }

        $vars = [
            'Namespace' => "App\\{$context}",
            'ClassName' => $name,
            'Bucket' => $bucket,
            'AttributeKey' => $bucket . '_id',
        ];

        $output->writeln("<info>vortos:make:quota-resolver</info> {$name} --context={$context}");
        $output->writeln('');

        $this->engine->write(
            "{$context}/Infrastructure/Quota/{$name}QuotaResolver.php",
            $this->engine->render('quota-resolver', $vars),
            $output,
        );

        return Command::SUCCESS;
    }
}
