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
        $source = realpath(__DIR__ . '/../stubs/' . $runtime);
        $projectRoot = getcwd();

        if ($source === false || !is_dir($source)) {
            $output->writeln("<error>Unknown runtime: $runtime. Use frankenphp or phpfpm</error>");
            return Command::FAILURE;
        }

        $this->copyDirectory($source, $projectRoot);
        $output->writeln("<info>Docker files published for $runtime runtime.</info>");
        return Command::SUCCESS;
    }

    private function copyDirectory(string $source, string $dest): void
    {
        $source = rtrim($source, DIRECTORY_SEPARATOR);

        foreach (new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($source, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST
        ) as $item) {
            $relativePath = substr($item->getPathname(), strlen($source) + 1);
            $target = $dest . DIRECTORY_SEPARATOR . $relativePath;

            if ($item->isDir()) {
                @mkdir($target, 0755, true);
            } else {
                @mkdir(dirname($target), 0755, true);
                copy($item->getPathname(), $target);
            }
        }
    }
}
