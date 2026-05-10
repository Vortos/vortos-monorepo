<?php

declare(strict_types=1);

namespace Vortos\Mcp\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\ChoiceQuestion;
use Symfony\Component\Console\Style\SymfonyStyle;
use Vortos\Mcp\Client\ClientConfigWriter;
use Vortos\Mcp\Client\ClientDetector;
use Vortos\Mcp\Client\KnownClients;

/**
 * Writes the Vortos MCP server entry into the user's AI client configuration.
 * The AI client will then auto-start `php bin/vortos vortos:mcp:serve` as a
 * stdio process whenever the project is open.
 */
#[AsCommand(
    name: 'vortos:mcp:install',
    description: 'Wire the Vortos MCP server into your AI client configuration',
)]
final class McpInstallCommand extends Command
{
    public function __construct(
        private readonly string $projectDir,
        private readonly ClientDetector $detector,
        private readonly ClientConfigWriter $writer,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption(
                'client',
                'c',
                InputOption::VALUE_REQUIRED,
                'AI client to configure: auto, claude, cursor, windsurf, all',
                'auto',
            )
            ->addOption('global', null, InputOption::VALUE_NONE, 'Write to the global AI client config (~/) instead of the project config');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io     = new SymfonyStyle($input, $output);
        $client = (string) $input->getOption('client');
        $global = (bool) $input->getOption('global');

        $knownClients = new KnownClients();

        $targets = match ($client) {
            'auto' => $this->autoTargets(),
            'all' => $knownClients->names(),
            default => [$client],
        };

        if ($targets === []) {
            if ($client === 'auto' && $input->isInteractive()) {
                $selected = $this->askClient($input, $output, $knownClients, $knownClients->names());
                if ($selected === null) {
                    $io->warning('MCP client setup cancelled.');
                    return Command::SUCCESS;
                }

                $targets = $selected;
            } else {
                $io->note(sprintf(
                    'No supported AI client config was detected. Choose one explicitly: php bin/vortos vortos:mcp:install --client=%s',
                    implode('|', $knownClients->names()),
                ));
                return Command::SUCCESS;
            }
        } elseif ($client === 'auto' && count($targets) > 1 && $input->isInteractive()) {
            $selected = $this->askClient($input, $output, $knownClients, $targets, allowAll: true);
            if ($selected === null) {
                $io->warning('MCP client setup cancelled.');
                return Command::SUCCESS;
            }

            $targets = $selected;
        }

        foreach ($targets as $target) {
            if ($knownClients->get($target) === null) {
                $io->error(sprintf('Unknown client "%s". Available: %s', $target, implode(', ', $knownClients->names())));
                return Command::FAILURE;
            }
        }

        $io->title('Vortos MCP Install');

        $failed = false;

        foreach ($targets as $target) {
            try {
                $result = $this->writer->write($target, $this->projectDir, $global);
                $action = match ($result['action']) {
                    'created'   => '<info>created</info>',
                    'updated'   => '<comment>updated</comment>',
                    'unchanged' => '<fg=gray>unchanged</>',
                    default     => $result['action'],
                };
                $io->writeln(sprintf('  %s  %s  →  %s', $action, $knownClients->get($target)['name'], $result['path']));
            } catch (\Throwable $e) {
                $io->writeln(sprintf('  <error>failed</error>  %s  —  %s', $target, $e->getMessage()));
                $failed = true;
            }
        }

        if ($failed) {
            return Command::FAILURE;
        }

        $io->newLine();
        $io->writeln('Run <info>php bin/vortos vortos:mcp:doctor</info> to verify the connection.');
        $io->writeln('Then open your AI client and ask: <comment>"Explain this Vortos project structure."</comment>');

        return Command::SUCCESS;
    }

    /** @return string[] */
    private function autoTargets(): array
    {
        $targets = [];

        foreach ($this->detector->detect() as $id => $status) {
            if ($status['detected']) {
                $targets[] = $id;
            }
        }

        return $targets;
    }

    /**
     * @param string[] $clientIds
     * @return ?string[]
     */
    private function askClient(
        InputInterface $input,
        OutputInterface $output,
        KnownClients $knownClients,
        array $clientIds,
        bool $allowAll = false,
    ): ?array
    {
        $choices = [];
        foreach ($clientIds as $id) {
            $client = $knownClients->get($id);
            if ($client === null) {
                continue;
            }

            $choices[] = sprintf('%s - %s', $id, $client['name']);
        }
        if ($allowAll) {
            $choices[] = 'all - All detected clients';
        }
        $choices[] = 'cancel - Cancel';

        $question = new ChoiceQuestion('Choose AI client for MCP', $choices, 0);
        $question->setErrorMessage('Client %s is not valid.');

        /** @var string $selected */
        $selected = $this->getHelper('question')->ask($input, $output, $question);
        $id = substr($selected, 0, (int) strpos($selected, ' - '));

        if ($id === 'cancel') {
            return null;
        }

        return $id === 'all' ? $clientIds : [$id];
    }
}
