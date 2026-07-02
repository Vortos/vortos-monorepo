<?php

declare(strict_types=1);

namespace Vortos\Pipeline\Console;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Vortos\Pipeline\Builder\PipelineBuilder;
use Vortos\Pipeline\Builder\StageGate;
use Vortos\Pipeline\Definition\PipelineDefinition;
use Vortos\Pipeline\Emitter\PipelineEmitterRegistry;
use Vortos\Pipeline\Model\SplitPackage;

#[AsCommand(name: 'pipeline:generate', description: 'Generate CI/CD workflow files from the pipeline model')]
final class PipelineGenerateCommand extends Command
{
    /**
     * @param list<SplitPackage> $splitPackages
     */
    public function __construct(
        private readonly PipelineEmitterRegistry $registry,
        private readonly PipelineBuilder $builder,
        private readonly StageGate $gate,
        private readonly array $splitPackages,
        private readonly string $projectDir,
        private readonly PipelineDefinition $definition,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('emitter', null, InputOption::VALUE_REQUIRED, 'Emitter driver key', 'github');
        $this->addOption('dry-run', null, InputOption::VALUE_NONE, 'Print artifacts to stdout, do not write files');
        $this->addOption('force', null, InputOption::VALUE_NONE, 'Overwrite existing workflow files');
        $this->addOption('json', null, InputOption::VALUE_NONE, 'Machine-readable output');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $emitterKey = (string) $input->getOption('emitter');
        $dryRun = (bool) $input->getOption('dry-run');
        $force = (bool) $input->getOption('force');
        $json = (bool) $input->getOption('json');

        $emitter = $this->registry->emitter($emitterKey);

        $pipeline = $this->builder->build(
            $this->definition,
            $this->splitPackages,
        );

        $artifacts = $emitter->emit($pipeline);

        $gated = $this->gate->gatedStages();
        $results = [];

        foreach ($artifacts as $artifact) {
            $targetPath = $this->projectDir . '/' . $artifact->relativePath;
            $dir = \dirname($targetPath);

            if ($dryRun) {
                $results[] = [
                    'path' => $artifact->relativePath,
                    'status' => 'dry-run',
                    'description' => $artifact->description,
                ];

                if (!$json) {
                    $output->writeln(sprintf('<info>[DRY-RUN]</info> %s — %s', $artifact->relativePath, $artifact->description));
                    $output->writeln($artifact->contents);
                    $output->writeln('');
                }

                continue;
            }

            if (!is_dir($dir)) {
                mkdir($dir, 0755, true);
            }

            if (is_file($targetPath)) {
                $existing = file_get_contents($targetPath);
                if ($existing === $artifact->contents) {
                    $results[] = [
                        'path' => $artifact->relativePath,
                        'status' => 'unchanged',
                        'description' => $artifact->description,
                    ];

                    if (!$json) {
                        $output->writeln(sprintf('<comment>[UNCHANGED]</comment> %s', $artifact->relativePath));
                    }

                    continue;
                }

                if (!$force) {
                    $results[] = [
                        'path' => $artifact->relativePath,
                        'status' => 'skipped',
                        'description' => $artifact->description,
                    ];

                    if (!$json) {
                        $output->writeln(sprintf(
                            '<error>[SKIPPED]</error> %s — file exists, use --force to overwrite',
                            $artifact->relativePath,
                        ));
                    }

                    continue;
                }
            }

            file_put_contents($targetPath, $artifact->contents);

            $results[] = [
                'path' => $artifact->relativePath,
                'status' => 'written',
                'description' => $artifact->description,
            ];

            if (!$json) {
                $output->writeln(sprintf('<info>[WRITTEN]</info> %s — %s', $artifact->relativePath, $artifact->description));
            }
        }

        if ($gated !== [] && !$json) {
            $output->writeln('');
            $output->writeln('<comment>Gated stages (future blocks):</comment>');
            foreach ($gated as $kind) {
                $output->writeln(sprintf('  • %s', $kind->value));
            }
        }

        if ($json) {
            $output->writeln(json_encode([
                'artifacts' => $results,
                'gated_stages' => array_map(
                    static fn ($k): string => $k->value,
                    $gated,
                ),
            ], \JSON_THROW_ON_ERROR | \JSON_PRETTY_PRINT));
        }

        return Command::SUCCESS;
    }
}
