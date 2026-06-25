<?php

declare(strict_types=1);

namespace Vortos\Deploy\Console;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Vortos\Deploy\Cutover\EdgeConfigGenerator;

#[AsCommand(name: 'deploy:edge:init', description: 'Scaffold edge Caddy configuration files')]
final class EdgeInitCommand extends Command
{
    public function __construct(
        private readonly EdgeConfigGenerator $generator,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('domain', InputArgument::REQUIRED, 'The domain for TLS/ACME');
        $this->addOption('output-dir', 'o', InputOption::VALUE_REQUIRED, 'Target directory', '.');
        $this->addOption('force', 'f', InputOption::VALUE_NONE, 'Overwrite existing files');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $domain = (string) $input->getArgument('domain');
        $dir = (string) $input->getOption('output-dir');
        $force = (bool) $input->getOption('force');

        if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
            $output->writeln(sprintf('<error>Cannot create directory: %s</error>', $dir));
            return Command::FAILURE;
        }

        $caddyConfigPath = $dir . '/caddy-config.json';
        $composePath = $dir . '/docker-compose.edge.yaml';

        if (!$force && (file_exists($caddyConfigPath) || file_exists($composePath))) {
            $output->writeln('<error>Files already exist. Use --force to overwrite.</error>');
            return Command::FAILURE;
        }

        file_put_contents($caddyConfigPath, $this->generator->generateCaddyConfigJson($domain));
        file_put_contents($composePath, $this->generator->generateEdgeComposeYaml($domain));

        $output->writeln(sprintf('Edge files written to %s/', $dir));

        return Command::SUCCESS;
    }
}
