<?php

declare(strict_types=1);

namespace Vortos\Mcp\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Vortos\Mcp\Client\ClientDetector;
use Vortos\Mcp\Server\McpServer;

#[AsCommand(
    name: 'vortos:mcp:doctor',
    description: 'Show Vortos MCP server status: detected AI clients, wired configs, and available tools',
)]
final class McpDoctorCommand extends Command
{
    public function __construct(
        private readonly string $projectDir,
        private readonly ClientDetector $detector,
        private readonly McpServer $server,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $io->title('Vortos MCP — Status');

        // Clients
        $io->section('AI Clients');
        $clients  = $this->detector->detect();
        $allGood  = true;

        foreach ($clients as $id => $status) {
            if ($status['configured']) {
                $io->writeln(sprintf(
                    '  <info>✔</info>  %-20s <fg=gray>%s</>',
                    $status['name'],
                    $status['config_path'],
                ));
            } elseif ($status['detected']) {
                $io->writeln(sprintf(
                    '  <comment>!</comment>  %-20s <fg=gray>%s — not configured, run: php bin/console vortos:mcp:install --client=%s</>',
                    $status['name'],
                    $status['config_path'],
                    $id,
                ));
                $allGood = false;
            } else {
                $io->writeln(sprintf('  <fg=gray>-</> %-20s not detected', $status['name']));
            }
        }

        // Server
        $io->section('MCP Server');
        $consolePath = $this->projectDir . '/bin/console';
        $io->writeln(sprintf('  Command: <info>php %s vortos:mcp:serve</info>', $consolePath));
        $io->writeln('  Transport: stdio (AI clients start this automatically)');

        // Tools
        $io->section('Available Tools');
        $modules  = require __DIR__ . '/../Data/modules.php';
        $toolDefs = [
            'get_conventions'     => 'Golden rules and naming conventions',
            'get_module_docs'     => 'Module reference and config options',
            'get_architecture'    => 'Layer rules, CQRS/DDD patterns, file structure',
            'get_best_practices'  => 'Performance, security, testing, worker mode',
            'get_mistakes'        => 'Antipatterns and what to do instead',
            'list_project_modules'=> 'Installed vortos/* packages from composer.lock',
            'read_project_config' => 'App config/*.php files and current configuration',
        ];

        foreach ($toolDefs as $name => $desc) {
            $io->writeln(sprintf('  <info>✔</info>  <comment>%-28s</comment> %s', $name, $desc));
        }

        // Summary
        $io->newLine();
        if ($allGood) {
            $io->success('MCP is ready. Open your AI client and ask: "Explain this Vortos project structure."');
        } else {
            $io->note('Some clients are not configured. Run php bin/console vortos:mcp:install to wire them.');
        }

        return Command::SUCCESS;
    }
}
