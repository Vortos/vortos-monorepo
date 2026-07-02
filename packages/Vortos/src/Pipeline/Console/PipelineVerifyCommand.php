<?php

declare(strict_types=1);

namespace Vortos\Pipeline\Console;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Vortos\Pipeline\Builder\PipelineBuilder;
use Vortos\Pipeline\Definition\PipelineDefinition;
use Vortos\Pipeline\Emitter\PipelineEmitterRegistry;
use Vortos\Pipeline\Model\SplitPackage;

#[AsCommand(name: 'pipeline:verify', description: 'Verify generated workflows match the pipeline model (drift detection)')]
final class PipelineVerifyCommand extends Command
{
    /**
     * @param list<SplitPackage> $splitPackages
     */
    public function __construct(
        private readonly PipelineEmitterRegistry $registry,
        private readonly PipelineBuilder $builder,
        private readonly array $splitPackages,
        private readonly string $projectDir,
        private readonly PipelineDefinition $definition,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('emitter', null, InputOption::VALUE_REQUIRED, 'Emitter driver key', 'github');
        $this->addOption('json', null, InputOption::VALUE_NONE, 'Machine-readable output');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $emitterKey = (string) $input->getOption('emitter');
        $json = (bool) $input->getOption('json');

        $emitter = $this->registry->emitter($emitterKey);

        $pipeline = $this->builder->build(
            $this->definition,
            $this->splitPackages,
        );

        $artifacts = $emitter->emit($pipeline);

        $drifted = [];
        $inSync = [];

        foreach ($artifacts as $artifact) {
            $targetPath = $this->projectDir . '/' . $artifact->relativePath;

            if (!is_file($targetPath)) {
                $drifted[] = [
                    'path' => $artifact->relativePath,
                    'reason' => 'missing',
                ];
                continue;
            }

            $existing = file_get_contents($targetPath);
            if ($existing !== $artifact->contents) {
                $drifted[] = [
                    'path' => $artifact->relativePath,
                    'reason' => 'content_changed',
                ];
                continue;
            }

            $inSync[] = $artifact->relativePath;
        }

        if ($json) {
            $output->writeln(json_encode([
                'status' => $drifted === [] ? 'in_sync' : 'drifted',
                'drifted' => $drifted,
                'in_sync' => $inSync,
            ], \JSON_THROW_ON_ERROR | \JSON_PRETTY_PRINT));
        } else {
            if ($drifted === []) {
                $output->writeln('<info>All generated workflows are in sync with the pipeline model.</info>');
            } else {
                $output->writeln('<error>Workflow drift detected:</error>');
                foreach ($drifted as $d) {
                    $output->writeln(sprintf('  • %s — %s', $d['path'], $d['reason']));
                }
                $output->writeln('');
                $output->writeln('Run <comment>pipeline:generate --force</comment> to regenerate.');
            }
        }

        return $drifted === [] ? Command::SUCCESS : Command::FAILURE;
    }
}
