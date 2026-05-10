<?php

declare(strict_types=1);

namespace Vortos\Mcp\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Vortos\Mcp\Server\McpServer;

#[AsCommand(
    name: 'vortos:mcp:serve',
    description: 'Start the Vortos MCP server (stdio mode — AI clients start this automatically)',
)]
final class McpServeCommand extends Command
{
    public function __construct(private readonly McpServer $server)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('http', null, InputOption::VALUE_NONE, 'Run in HTTP mode instead of stdio (not yet implemented)')
            ->addOption('port', null, InputOption::VALUE_OPTIONAL, 'HTTP port (only with --http)', 8787);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        if ($input->getOption('http')) {
            $output->writeln('<error>HTTP mode is not yet implemented. Use stdio mode (the default).</error>');
            return Command::FAILURE;
        }

        // Stdio mode — all communication on STDIN/STDOUT. STDERR is safe for debug output.
        $this->server->run();

        return Command::SUCCESS;
    }
}
