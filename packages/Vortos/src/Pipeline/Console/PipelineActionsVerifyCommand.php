<?php

declare(strict_types=1);

namespace Vortos\Pipeline\Console;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Vortos\Pipeline\Builder\KnownActionFactory;
use Vortos\Pipeline\Verification\ActionPinVerifier;

/**
 * B7 gate: resolves every pinned GitHub Action SHA against the GitHub API and fails closed if any
 * pin does not exist. Network-dependent by nature, so it is a discrete command (wire it into the
 * framework's own CI); the offline {@see \Vortos\Pipeline\Tests\Unit\Builder\KnownActionPinIntegrityTest}
 * catches structural defects without a token.
 */
#[AsCommand(
    name: 'pipeline:actions:verify',
    description: 'Verify every pinned GitHub Action SHA exists upstream (fail-closed).',
)]
final class PipelineActionsVerifyCommand extends Command
{
    public function __construct(private readonly ActionPinVerifier $verifier)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('json', null, InputOption::VALUE_NONE, 'Machine-readable output');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $results = $this->verifier->verify(KnownActionFactory::all());
        $ok = $this->verifier->allExist($results);

        if ($input->getOption('json')) {
            $output->writeln((string) json_encode(
                ['ok' => $ok, 'pins' => $results],
                JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES,
            ));

            return $ok ? self::SUCCESS : self::FAILURE;
        }

        foreach ($results as $result) {
            if ($result['exists']) {
                $line = sprintf('<info>✓</info> %s', $result['ref']);
                if ($result['note'] !== null) {
                    $line .= sprintf(' <comment>(%s)</comment>', $result['note']);
                }
            } else {
                $line = sprintf('<error>✗ %s — %s</error>', $result['ref'], $result['note'] ?? 'does not exist');
            }
            $output->writeln($line);
        }

        $output->writeln($ok
            ? '<info>All pinned actions resolve.</info>'
            : '<error>One or more pinned actions do not exist — regenerate the pins.</error>');

        return $ok ? self::SUCCESS : self::FAILURE;
    }
}
