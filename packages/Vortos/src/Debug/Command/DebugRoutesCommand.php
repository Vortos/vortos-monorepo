<?php

declare(strict_types=1);

namespace Vortos\Debug\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Helper\TableStyle;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'vortos:debug:routes',
    description: 'List all registered routes',
)]
final class DebugRoutesCommand extends Command
{
    private const METHOD_COLORS = [
        'GET'    => 'green',
        'POST'   => 'yellow',
        'PUT'    => 'blue',
        'PATCH'  => 'cyan',
        'DELETE' => 'red',
        'HEAD'   => 'gray',
    ];

    public function __construct(private readonly array $routes)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('filter', 'f', InputOption::VALUE_REQUIRED, 'Filter by path or route name')
            ->addOption('method', 'm', InputOption::VALUE_REQUIRED, 'Filter by HTTP method')
            ->addOption('full', null, InputOption::VALUE_NONE, 'Show full namespace and route name')
            ->addOption('json', null, InputOption::VALUE_NONE, 'Output as JSON');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $routes = $this->applyFilters($this->routes, $input);

        if ($input->getOption('json')) {
            $output->writeln(json_encode(array_values($routes), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            return Command::SUCCESS;
        }

        $output->writeln('');
        $output->writeln(sprintf(
            ' <fg=white;options=bold>Vortos Routes</> <fg=gray>(%d registered)</>',
            count($routes),
        ));
        $output->writeln('');

        if (empty($routes)) {
            $output->writeln(' <fg=yellow>No routes match the given filters.</>');
            $output->writeln('');
            return Command::SUCCESS;
        }

        $tableStyle = (new TableStyle())
            ->setHorizontalBorderChars('')
            ->setVerticalBorderChars(' ')
            ->setCrossingChars('', '', '', '', '', '', '', '', '');

        $table = new Table($output);
        $table->setStyle($tableStyle);
        $verbose = $input->getOption('full');

        $table->setHeaders([
            '<fg=gray>Method</>',
            '<fg=gray>Path</>',
            '<fg=gray>Name</>',
            '<fg=gray>Controller</>',
        ]);

        foreach ($routes as $route) {
            $table->addRow([
                $this->formatMethods($route['methods']),
                sprintf('<fg=white>%s</>', $route['path']),
                sprintf('<fg=gray>%s</>', $this->formatName($route['name'], $verbose)),
                $this->formatController($route['controller'], $verbose),
            ]);
        }

        $table->render();
        $output->writeln('');

        return Command::SUCCESS;
    }

    private function applyFilters(array $routes, InputInterface $input): array
    {
        if ($filter = $input->getOption('filter')) {
            $routes = array_filter(
                $routes,
                fn($r) => str_contains($r['path'], $filter) || str_contains($r['name'], $filter),
            );
        }

        if ($method = $input->getOption('method')) {
            $method = strtoupper($method);
            $routes = array_filter($routes, fn($r) => in_array($method, $r['methods'], true));
        }

        return array_values($routes);
    }

    private function formatMethods(array $methods): string
    {
        $formatted = [];

        foreach ($methods as $method) {
            $color      = self::METHOD_COLORS[$method] ?? 'white';
            $formatted[] = sprintf('<fg=%s;options=bold>%-7s</>', $color, $method);
        }

        return implode(' ', $formatted);
    }

    private function formatName(string $name, bool $verbose): string
    {
        if ($verbose) {
            return $name;
        }

        // Auto-generated names are very long — truncate them
        return strlen($name) > 32 ? substr($name, 0, 31) . '…' : $name;
    }

    private function formatController(string $controller, bool $verbose): string
    {
        if (!str_contains($controller, '::')) {
            return $controller;
        }

        [$class, $method] = explode('::', $controller, 2);

        $parts     = explode('\\', $class);
        $shortName = array_pop($parts);
        $namespace = implode('\\', $parts);

        if ($verbose) {
            return sprintf('<fg=gray>%s\\</><fg=white>%s</><fg=gray>::%s</>', $namespace, $shortName, $method);
        }

        return sprintf('<fg=white>%s</><fg=gray>::%s</>', $shortName, $method);
    }
}
