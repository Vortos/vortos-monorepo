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
        $exist = $this->verifier->allExist($results);
        $runtimesOk = $this->verifier->allRuntimesSupported($results);
        $ok = $exist && $runtimesOk;

        if ($input->getOption('json')) {
            $output->writeln((string) json_encode(
                [
                    'ok' => $ok,
                    'all_exist' => $exist,
                    'all_runtimes_supported' => $runtimesOk,
                    'has_deprecated_runtimes' => $this->verifier->hasDeprecatedRuntimes($results),
                    'pins' => $results,
                ],
                JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES,
            ));

            return $ok ? self::SUCCESS : self::FAILURE;
        }

        foreach ($results as $result) {
            $line = $this->renderPin($result);
            $output->writeln($line);
        }

        if (!$exist) {
            $output->writeln('<error>One or more pinned actions do not exist — regenerate the pins.</error>');
        }
        if (!$runtimesOk) {
            $output->writeln('<error>One or more pinned actions run on a removed runtime — bump them.</error>');
        }
        if ($runtimesOk && $this->verifier->hasDeprecatedRuntimes($results)) {
            $output->writeln('<comment>Some actions run on a deprecated runtime (node20); no newer major is available upstream yet.</comment>');
        }
        if ($ok) {
            $output->writeln('<info>All pinned actions resolve and run on a supported runtime.</info>');
        }

        return $ok ? self::SUCCESS : self::FAILURE;
    }

    /** @param array{ref: string, exists: bool, runtime: ?string, runtime_status: string, note: ?string} $result */
    private function renderPin(array $result): string
    {
        if (!$result['exists']) {
            return sprintf('<error>✗ %s — %s</error>', $result['ref'], $result['note'] ?? 'does not exist');
        }

        $runtime = $result['runtime'] !== null ? sprintf(' [%s]', $result['runtime']) : '';

        $line = match ($result['runtime_status']) {
            'removed' => sprintf('<error>✗ %s%s — runtime removed by GitHub</error>', $result['ref'], $runtime),
            'deprecated' => sprintf('<comment>! %s%s — deprecated runtime</comment>', $result['ref'], $runtime),
            default => sprintf('<info>✓</info> %s%s', $result['ref'], $runtime),
        };

        if ($result['note'] !== null && $result['runtime_status'] !== 'removed') {
            $line .= sprintf(' <comment>(%s)</comment>', $result['note']);
        }

        return $line;
    }
}
