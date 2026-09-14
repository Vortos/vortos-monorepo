<?php

declare(strict_types=1);

namespace Vortos\Deploy\Console;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;
use Vortos\Deploy\Driver\Compose\ComposeCliValidator;
use Vortos\Deploy\Topology\ComposeTopologySync;
use Vortos\Deploy\Topology\HostPathSync;
use Vortos\Deploy\Topology\SyncedPathResult;
use Vortos\Deploy\Topology\SyncedPathSpec;
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
            // RC-3: each path the topology bind-mounts, copied from beside the source onto the host
            // beside the target BEFORE the topology is written. Emitted by the generated deploy script
            // from config/pipeline.php; validated again here because this runs as root.
            ->addOption('synced-path', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'A bind-mounted path to copy from the image: <path>@<mode>@<uid>:<gid>@<service>[,<service>]')
            // Default is a DRY RUN. A step that rewrites the file describing how the database runs
            // should be opted into explicitly, so running this by hand to see the drift is safe.
            ->addOption('apply', null, InputOption::VALUE_NONE, 'Actually write the host file')
            ->addOption('json', null, InputOption::VALUE_NONE, 'Machine-readable output');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $json = (bool) $input->getOption('json');
        $apply = (bool) $input->getOption('apply');
        $source = (string) $input->getOption('source');
        $target = (string) $input->getOption('target');

        $stateful = array_values(array_filter(array_map(
            'trim',
            explode(',', (string) $input->getOption('stateful')),
        )));

        $sync = new ComposeTopologySync(
            sourcePath: $source,
            targetPath: $target,
            statefulServices: $stateful,
            // Applied to the STAGED file, beside the one it replaces, so env_file entries and
            // relative mounts resolve as they will at runtime. Validating the copy inside the
            // release image instead asks whether the host's secrets exist in the image — they do
            // not and must not, and that mistake failed a production deploy.
            validator: $this->validator,
        );

        try {
            /** @var list<string> $rawSpecs */
            $rawSpecs = array_values((array) $input->getOption('synced-path'));
            $specs = array_map(static fn (string $spec): SyncedPathSpec => SyncedPathSpec::parse($spec), $rawSpecs);

            // Paths first: the topology written next references them, and its validator resolves
            // them against the host. A refusal here leaves the topology untouched too.
            $paths = $specs === [] ? [] : (new HostPathSync(\dirname($source), \dirname($target)))->sync($specs, $apply);
            $result = $sync->sync($apply);
        } catch (Throwable $e) {
            // Fail closed and loudly. Every failure path leaves the live files untouched or, for a
            // synced path, backed up first — a red step here never means "silently half changed".
            if ($json) {
                $output->writeln((string) json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_THROW_ON_ERROR));
            } else {
                $output->writeln(sprintf('<error>Topology sync failed: %s</error>', $e->getMessage()));
            }

            return self::FAILURE;
        }

        $recreate = $this->recreateCommand($target, $paths);

        if ($json) {
            $output->writeln((string) json_encode(
                ['ok' => true] + $result->toArray() + [
                    'synced_paths' => array_map(static fn (SyncedPathResult $p): array => $p->toArray(), $paths),
                    'recreate_command' => $recreate,
                ],
                JSON_THROW_ON_ERROR,
            ));
        } else {
            foreach ($paths as $path) {
                $output->writeln(sprintf(
                    'synced path %s: %s (%d changed, %d removed)',
                    $path->path,
                    $path->status->value,
                    \count($path->changedFiles),
                    \count($path->removedFiles),
                ));
            }
            $output->writeln($result->summary());
        }

        // Drift on a stateful service is surfaced prominently but does NOT fail the deploy. The
        // topology on disk is now correct and the running containers are not, which is a state
        // somebody has to resolve on purpose — blocking every future deploy until they do would
        // punish the wrong thing and encourage skipping the gate.
        //
        // SURFACED IN BOTH MODES, and that is a fix rather than a tidy-up. This notice used to be
        // skipped whenever --json was passed, which is the only way the deploy ever invokes this
        // command — so the one caller that always runs it was the one caller that never saw it. The
        // first production sync reported kafka as needing convergence into a log line nobody was
        // told to read, and the remedy was never printed anywhere at all.
        //
        // In JSON mode it goes to STDERR so the contract on stdout stays a single parseable object.
        $stream = $output instanceof ConsoleOutputInterface ? $output->getErrorOutput() : $output;

        if ($result->needsManualConvergence()) {
            $notice = sprintf(
                'Manual convergence required for: %s. Recreating these is a data-availability '
                . 'decision, so the deploy will not do it. When you choose to take the downtime: %s',
                implode(', ', $result->statefulServices),
                (string) $result->convergenceCommand(),
            );
            $stream->writeln($json ? $notice : sprintf('<comment>%s</comment>', $notice));
        }

        // A replaced single-file bind is invisible to a running container, which keeps the old inode —
        // so the new copy is on disk and not in effect. Said as plainly as stateful drift, for the
        // same reason: a notice that must be gone looking for is not a notice.
        if ($recreate !== null) {
            $notice = sprintf('A bind-mounted file changed; the services reading it keep the previous copy until recreated: %s', $recreate);
            $stream->writeln($json ? $notice : sprintf('<comment>%s</comment>', $notice));
        }

        return self::SUCCESS;
    }

    /** @param list<SyncedPathResult> $paths */
    private function recreateCommand(string $target, array $paths): ?string
    {
        $services = [];
        foreach ($paths as $path) {
            if ($path->needsRecreate()) {
                array_push($services, ...$path->services);
            }
        }

        if ($services === []) {
            return null;
        }

        // --no-deps for the same reason as convergenceCommand(): without it compose may recreate the
        // named service's dependencies, datastores included.
        return sprintf('docker compose -f %s up -d --no-deps --force-recreate %s', $target, implode(' ', array_values(array_unique($services))));
    }
}
