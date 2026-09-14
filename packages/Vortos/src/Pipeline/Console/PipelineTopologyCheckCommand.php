<?php

declare(strict_types=1);

namespace Vortos\Pipeline\Console;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Vortos\Pipeline\Definition\PipelineDefinition;
use Vortos\Pipeline\Topology\TopologyPolicy;
use Vortos\Pipeline\Topology\TopologyViolation;

/**
 * Refuses a compose topology that breaks any {@see TopologyPolicy} rule — e.g. hands an env file to a
 * service config/pipeline.php did not authorise (RC-4). Emitted into the gating tests job whenever the
 * topology is synced to the host.
 */
#[AsCommand(name: PipelineTopologyCheckCommand::NAME, description: 'Refuse a compose topology that breaks a pipeline topology rule (e.g. env files reaching undeclared services)')]
final class PipelineTopologyCheckCommand extends Command
{
    public const NAME = 'pipeline:topology:check';

    public function __construct(
        private readonly PipelineDefinition $definition,
        private readonly string $projectDir,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('json', null, InputOption::VALUE_NONE, 'Machine-readable output');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $json = (bool) $input->getOption('json');
        $relative = $this->definition->composeTopologyPath;
        $path = rtrim($this->projectDir, '/') . '/' . $relative;
        $policy = TopologyPolicy::forDefinition($this->definition);

        if (!is_file($path) || !is_readable($path)) {
            // Fail closed: a missing topology proves nothing about what reaches the host.
            $reason = sprintf('compose topology %s is not readable in %s', $relative, $this->projectDir);
            $output->writeln($json
                ? (string) json_encode(['status' => 'refused', 'topology' => $relative, 'rules' => $policy->ruleNames(), 'error' => $reason], \JSON_THROW_ON_ERROR)
                : sprintf('<error>%s</error>', $reason));

            return self::FAILURE;
        }

        $violations = $policy->evaluate((string) file_get_contents($path));

        if ($json) {
            $output->writeln((string) json_encode([
                'status' => $violations === [] ? 'clear' : 'refused',
                'topology' => $relative,
                'rules' => $policy->ruleNames(),
                'violations' => array_map(static fn (TopologyViolation $v): array => $v->toArray(), $violations),
            ], \JSON_THROW_ON_ERROR | \JSON_PRETTY_PRINT));
        } elseif ($violations === []) {
            $output->writeln(sprintf('<info>%s holds every topology rule (%s).</info>', $relative, implode(', ', $policy->ruleNames())));
        } else {
            $output->writeln(sprintf('<error>%s breaks topology rules:</error>', $relative));
            foreach ($violations as $violation) {
                $output->writeln('  • ' . $violation->message());
            }
        }

        return $violations === [] ? self::SUCCESS : self::FAILURE;
    }
}
