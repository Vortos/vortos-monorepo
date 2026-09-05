<?php

declare(strict_types=1);

namespace Vortos\Deploy\Console;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;
use Vortos\Deploy\Driver\Compose\ComposeCliValidator;
use Vortos\Deploy\Topology\ComposeTopologySync;
use Vortos\Deploy\Topology\TopologyValidatorInterface;

/**
 * Converge the host's compose topology onto the version that shipped inside the release image.
 *
 * Runs on the target as part of the deploy flow, from the image whose cosign signature was verified
 * moments earlier — so the topology inherits the same supply-chain guarantee as the code it
 * describes, with no second transfer channel to secure.
 *
 * Writes the desired state; never applies it. See {@see ComposeTopologySync} for why those are
 * deliberately separate acts.
 */
#[AsCommand(
    name: ComposeSyncCommand::NAME,
    description: 'Sync the host compose topology from the release image (writes desired state; never recreates containers).',
)]
final class ComposeSyncCommand extends Command
{
    public const NAME = 'vortos:deploy:compose:sync';

    public function __construct(
        /**
         * Optional second opinion from the tool that will run the topology. Absent, the structural
         * checks in ComposeTopologySync still apply — they are what makes this step safe on any
         * host rather than only where the tooling happens to exist.
         */
        private readonly TopologyValidatorInterface $validator = new ComposeCliValidator(),
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('source', null, InputOption::VALUE_REQUIRED, 'Topology inside the release image', '/var/www/html/docker-compose.prod.yaml')
            ->addOption('target', null, InputOption::VALUE_REQUIRED, 'Topology on the host', '/opt/vortos/docker-compose.prod.yaml')
            ->addOption('stateful', null, InputOption::VALUE_REQUIRED, 'Comma-separated services that must never be converged implicitly', implode(',', ComposeTopologySync::DEFAULT_STATEFUL_SERVICES))
            // Default is a DRY RUN. A step that rewrites the file describing how the database runs
            // should be opted into explicitly, so running this by hand to see the drift is safe.
            ->addOption('apply', null, InputOption::VALUE_NONE, 'Actually write the host file')
            ->addOption('json', null, InputOption::VALUE_NONE, 'Machine-readable output');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $json = (bool) $input->getOption('json');

        $stateful = array_values(array_filter(array_map(
            'trim',
            explode(',', (string) $input->getOption('stateful')),
        )));

        $sync = new ComposeTopologySync(
            sourcePath: (string) $input->getOption('source'),
            targetPath: (string) $input->getOption('target'),
            statefulServices: $stateful,
            // Applied to the STAGED file, beside the one it replaces, so env_file entries and
            // relative mounts resolve as they will at runtime. Validating the copy inside the
            // release image instead asks whether the host's secrets exist in the image — they do
            // not and must not, and that mistake failed a production deploy.
            validator: $this->validator,
        );

        try {
            $result = $sync->sync((bool) $input->getOption('apply'));
        } catch (Throwable $e) {
            // Fail closed and loudly. Every failure path in ComposeTopologySync leaves the live file
            // untouched, so a red step here means "nothing changed", never "half changed".
            if ($json) {
                $output->writeln((string) json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_THROW_ON_ERROR));
            } else {
                $output->writeln(sprintf('<error>Topology sync failed: %s</error>', $e->getMessage()));
            }

            return self::FAILURE;
        }

        if ($json) {
            $output->writeln((string) json_encode(
                ['ok' => true] + $result->toArray(),
                JSON_THROW_ON_ERROR,
            ));
        } else {
            $output->writeln($result->summary());
        }

        // Drift on a stateful service is surfaced prominently but does NOT fail the deploy. The
        // topology on disk is now correct and the running containers are not, which is a state
        // somebody has to resolve on purpose — blocking every future deploy until they do would
        // punish the wrong thing and encourage skipping the gate.
        if ($result->needsManualConvergence() && !$json) {
            $output->writeln(sprintf(
                '<comment>Manual convergence required for: %s. Recreating these is a '
                . 'data-availability decision — run docker compose up -d --no-deps <service> when '
                . 'you choose to take the downtime.</comment>',
                implode(', ', $result->statefulServices),
            ));
        }

        return self::SUCCESS;
    }

}
