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
    name: 'vortos:make:controller',
    description: 'Generate an HTTP controller and its request class',
)]
final class MakeControllerCommand extends Command
{
    public function __construct(private readonly GeneratorEngine $engine)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('name', InputArgument::REQUIRED, 'Controller name without "Controller" suffix (e.g. RegisterUser)')
            ->addOption('context', 'c', InputOption::VALUE_REQUIRED, 'Domain context folder (e.g. User)')
            ->addOption('route', null, InputOption::VALUE_OPTIONAL, 'Route path (defaults to kebab-case of name, e.g. /register-user)')
            ->addOption('route-name', null, InputOption::VALUE_OPTIONAL, 'Route name (defaults to snake_case of name, e.g. register_user)')
            ->addOption('method', null, InputOption::VALUE_OPTIONAL, 'HTTP method (default: POST)', 'POST');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $name    = (string) $input->getArgument('name');
        $context = (string) $input->getOption('context');

        if ($context === '') {
            $output->writeln('<error>--context is required. Example: --context=User</error>');
            return Command::FAILURE;
        }

        $route     = (string) ($input->getOption('route') ?? $this->toKebabCase($name));
        $routeName = (string) ($input->getOption('route-name') ?? $this->toSnakeCase($name));
        $method    = strtoupper((string) $input->getOption('method'));

        $vars = [
            'Namespace'   => "App\\{$context}",
            'ClassName'   => $name,
            'RoutePrefix' => ltrim($route, '/'),
            'RouteName'   => $routeName,
            'RouteMethod' => $method,
        ];

        $output->writeln("<info>vortos:make:controller</info> {$name} --context={$context}");
        $output->writeln('');

        $this->engine->write(
            "{$context}/Representation/Controller/{$name}Controller.php",
            $this->engine->render('controller', $vars),
            $output,
        );
        $this->engine->write(
            "{$context}/Representation/Request/{$name}Request.php",
            $this->engine->render('request', $vars),
            $output,
        );

        return Command::SUCCESS;
    }

    private function toKebabCase(string $name): string
    {
        return strtolower(preg_replace('/(?<!^)[A-Z]/', '-$0', $name) ?? $name);
    }

    private function toSnakeCase(string $name): string
    {
        return strtolower(preg_replace('/(?<!^)[A-Z]/', '_$0', $name) ?? $name);
    }
}
