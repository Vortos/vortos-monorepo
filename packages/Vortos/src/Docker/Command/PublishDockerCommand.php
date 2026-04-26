<?php
declare(strict_types=1);

namespace Vortos\Docker\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'vortos:docker:publish',
    description: 'Publish Docker files to your project'
)]
final class PublishDockerCommand extends Command
{
    protected function configure(): void
    {
        $this->addOption(
            'runtime',
            'r',
            InputOption::VALUE_OPTIONAL,
            'Runtime to use: frankenphp or phpfpm',
            'frankenphp'
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $runtime = $input->getOption('runtime');
        $source = __DIR__ . '/../../stubs/' . $runtime;
        $projectRoot = getcwd();

        if (!is_dir($source)) {
            $output->writeln("<error>Unknown runtime: $runtime. Use frankenphp or phpfpm</error>");
            return Command::FAILURE;
        }

        $this->copyDirectory($source, $projectRoot);
        $output->writeln("<info>Docker files published for $runtime runtime.</info>");
        return Command::SUCCESS;
    }

    private function copyDirectory(string $source, string $dest): void
    {
        foreach (new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($source, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST
        ) as $item) {
            $target = $dest . '/' . str_replace($source . '/', '', $item->getPathname());
            if ($item->isDir()) {
                @mkdir($target, 0755, true);
            } else {
                copy($item->getPathname(), $target);
            }
        }
    }
}
