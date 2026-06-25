<?php

declare(strict_types=1);

namespace Vortos\Release\Console;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Vortos\Release\Git\GitRepositoryInterface;
use Vortos\Release\Git\GitRemoteResolver;
use Vortos\Release\Service\ChangelogGenerator;
use Vortos\Release\Service\CoordinatedTagger;
use Vortos\Release\Service\PackageDiscovery;
use Vortos\Release\Service\PackagePlanInput;
use Vortos\Release\Service\ReleaseException;
use Vortos\Release\Service\ReleasePlanner;
use Vortos\Release\Service\VersionResolver;
use Vortos\Release\Service\VersionSkewGuard;
use Vortos\Release\Version\BumpLevel;
use Vortos\Release\Version\ConventionalCommitParser;
use Vortos\Release\Version\VersioningStrategyInterface;

#[AsCommand(
    name: 'vortos:release:tag',
    description: 'Coordinated release tagging across all vortos packages (dry-run by default).',
)]
final class ReleaseTagCommand extends Command
{
    public function __construct(
        private readonly GitRepositoryInterface $git,
        private readonly PackageDiscovery $packageDiscovery,
        private readonly VersioningStrategyInterface $strategy,
        private readonly ReleasePlanner $planner,
        private readonly CoordinatedTagger $tagger,
        private readonly VersionSkewGuard $skewGuard,
        private readonly GitRemoteResolver $remoteResolver,
        private readonly ConventionalCommitParser $commitParser,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('apply', null, InputOption::VALUE_NONE, 'Actually create and push tags (default is dry-run)')
            ->addOption('undo', null, InputOption::VALUE_REQUIRED, 'Undo a previous tagging transaction by ID')
            ->addOption('bump', null, InputOption::VALUE_REQUIRED, 'Force bump level: auto|patch|minor|major', 'auto')
            ->addOption('pre', null, InputOption::VALUE_REQUIRED, 'Set prerelease identifier on the version')
            ->addOption('sign', null, InputOption::VALUE_NONE, 'Sign tags with GPG/SSH')
            ->addOption('allow-dirty', null, InputOption::VALUE_NONE, 'Allow tagging with a dirty working tree')
            ->addOption('package', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Tag only specific package(s)')
            ->addOption('json', null, InputOption::VALUE_NONE, 'Output plan as JSON');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $undoId = $input->getOption('undo');
        if (\is_string($undoId) && $undoId !== '') {
            return $this->executeUndo($undoId, $output);
        }

        if (!$input->getOption('allow-dirty') && !$this->git->isClean()) {
            $output->writeln('<error>Working tree is dirty. Commit or stash changes first, or use --allow-dirty.</error>');

            return self::FAILURE;
        }

        $packages = $this->packageDiscovery->discover();

        if ($packages === []) {
            $output->writeln('<error>No vortos/vortos-* packages found.</error>');

            return self::FAILURE;
        }

        $packageFilter = $input->getOption('package');
        if (\is_array($packageFilter) && $packageFilter !== []) {
            $packages = array_values(array_filter(
                $packages,
                static fn ($p) => \in_array($p->name, $packageFilter, true),
            ));

            if ($packages === []) {
                $output->writeln('<error>No matching packages found for the given --package filter.</error>');

                return self::FAILURE;
            }
        }

        $skewed = $this->skewGuard->detectSkew($packages);
        $skewDetected = $skewed !== [];

        if ($skewDetected && $input->getOption('apply')) {
            $output->writeln(sprintf(
                '<error>Version skew detected for: %s. Cannot apply tags with skewed packages.</error>',
                implode(', ', $skewed),
            ));

            return self::FAILURE;
        }

        $versionResolver = new VersionResolver($this->git, $this->strategy);
        $latestTag = $versionResolver->latestTag();
        $currentVersion = $versionResolver->currentVersion();

        $forceBump = $this->parseBumpOption($input->getOption('bump'));
        if ($forceBump === false) {
            $output->writeln('<error>Invalid --bump value. Use: auto, patch, minor, major.</error>');

            return self::FAILURE;
        }

        $rawCommits = $this->git->commitsBetween($latestTag, 'HEAD');
        $parsedCommits = [];
        foreach ($rawCommits as $raw) {
            $parsedCommits[] = $this->commitParser->parse($raw->rawMessage, $raw->sha);
        }

        $inputs = [];
        foreach ($packages as $pkg) {
            $inputs[] = new PackagePlanInput(
                packageName: $pkg->name,
                packagePath: $pkg->path,
                currentVersion: $currentVersion,
                commitRange: ($latestTag ?? 'ROOT') . '..HEAD',
                rawCommits: $rawCommits,
                parsedCommits: $parsedCommits,
                remote: $this->remoteResolver->remoteFor($pkg->name),
            );
        }

        $txId = bin2hex(random_bytes(16));
        $plan = $this->planner->plan($inputs, $txId, $skewDetected, forceBump: $forceBump);

        if ($input->getOption('json')) {
            $output->writeln((string) json_encode($plan->toArray(), JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return $plan->hasChanges() ? self::SUCCESS : self::SUCCESS;
        }

        $output->writeln($plan->render());

        if (!$plan->hasChanges()) {
            $output->writeln('<comment>No releasable changes found.</comment>');

            return self::SUCCESS;
        }

        if (!$input->getOption('apply')) {
            $output->writeln('<comment>Dry-run complete. Use --apply to create and push tags.</comment>');

            return self::SUCCESS;
        }

        try {
            $tx = $this->tagger->apply($plan, push: true, sign: (bool) $input->getOption('sign'));
            $output->writeln(sprintf('<info>Release %s complete. Transaction: %s</info>', $plan->packages[0]->nextVersion, $tx->id));

            return self::SUCCESS;
        } catch (ReleaseException $e) {
            $output->writeln(sprintf('<error>%s</error>', $e->getMessage()));

            return self::FAILURE;
        }
    }

    private function executeUndo(string $txId, OutputInterface $output): int
    {
        try {
            $tx = $this->tagger->undo($txId);
            $output->writeln(sprintf('<info>Transaction %s undone. %d tag(s) removed.</info>', $tx->id, \count($tx->tags)));

            return self::SUCCESS;
        } catch (ReleaseException $e) {
            $output->writeln(sprintf('<error>%s</error>', $e->getMessage()));

            return self::FAILURE;
        }
    }

    private function parseBumpOption(mixed $value): BumpLevel|null|false
    {
        if (!\is_string($value)) {
            return null;
        }

        return match ($value) {
            'auto' => null,
            'patch' => BumpLevel::Patch,
            'minor' => BumpLevel::Minor,
            'major' => BumpLevel::Major,
            default => false,
        };
    }
}
